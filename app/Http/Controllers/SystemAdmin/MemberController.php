<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(): View
    {
        return view('system-admin.members.index', [
            'members' => User::query()->with('workspaces.organization')->orderBy('name')->get(),
            'workspaces' => Workspace::query()->with('organization')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'assignment_type' => ['required', Rule::in(['new_workspace', 'existing_workspace'])],
            'organization_name' => ['nullable', 'required_if:assignment_type,new_workspace', 'string', 'max:255'],
            'workspace_name' => ['nullable', 'required_if:assignment_type,new_workspace', 'string', 'max:255'],
            'workspace_id' => ['nullable', 'required_if:assignment_type,existing_workspace', 'integer', 'exists:workspaces,id'],
            'workspace_role' => ['nullable', 'required_if:assignment_type,existing_workspace', Rule::in([
                WorkspaceMember::ROLE_ADMIN,
                WorkspaceMember::ROLE_MEMBER,
                WorkspaceMember::ROLE_VIEWER,
            ])],
        ]);

        DB::transaction(function () use ($validated): void {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            if ($validated['assignment_type'] === 'new_workspace') {
                $organization = Organization::create([
                    'name' => $validated['organization_name'],
                    'slug' => $this->uniqueOrganizationSlug($validated['organization_name']),
                ]);
                $workspace = Workspace::create([
                    'organization_id' => $organization->id,
                    'name' => $validated['workspace_name'],
                    'slug' => $this->uniqueWorkspaceSlug($organization, $validated['workspace_name']),
                ]);
                $organizationRole = OrganizationUser::ROLE_OWNER;
                $workspaceRole = WorkspaceMember::ROLE_OWNER;
            } else {
                $workspace = Workspace::query()->with('organization')->findOrFail($validated['workspace_id']);
                $organization = $workspace->organization;
                $organizationRole = OrganizationUser::ROLE_MEMBER;
                $workspaceRole = $validated['workspace_role'];
            }

            $organization->users()->syncWithoutDetaching([$user->id => [
                'role' => $organizationRole,
                'joined_at' => now(),
            ]]);
            $workspace->users()->attach($user->id, [
                'role' => $workspaceRole,
                'joined_at' => now(),
            ]);
        });

        return redirect()->route('system-admin.members.index')->with('status', 'メンバーを登録しました。');
    }

    public function edit(User $user): View
    {
        return view('system-admin.members.edit', [
            'member' => $user->load('workspaces.organization'),
            'workspaces' => Workspace::query()->with('organization')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
            'is_system_admin' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->is_system_admin && (! $validated['is_system_admin'] || ! $validated['is_active'])) {
            $otherActiveAdmins = User::query()
                ->whereKeyNot($user->id)
                ->where('is_system_admin', true)
                ->where('is_active', true)
                ->exists();

            if (! $otherActiveAdmins) {
                throw ValidationException::withMessages(['is_system_admin' => '最後の有効なSystem Adminは解除・停止できません。']);
            }
        }

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'is_system_admin' => $validated['is_system_admin'],
            'is_active' => $validated['is_active'],
        ]);

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        $user->save();

        return redirect()->route('system-admin.members.edit', $user)->with('status', 'メンバー情報を更新しました。');
    }

    public function storeWorkspace(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateWorkspaceMembership($request, $user);
        $workspace = Workspace::query()->with('organization')->findOrFail($validated['workspace_id']);

        DB::transaction(function () use ($user, $workspace, $validated): void {
            $workspace->organization->users()->syncWithoutDetaching([$user->id => [
                'role' => OrganizationUser::ROLE_MEMBER,
                'joined_at' => now(),
            ]]);
            $workspace->users()->attach($user->id, [
                'role' => $validated['workspace_role'],
                'joined_at' => now(),
            ]);
        });

        return back()->with('status', 'Workspaceへ追加しました。');
    }

    public function updateWorkspace(Request $request, User $user, Workspace $workspace): RedirectResponse
    {
        abort_unless($user->canAccessWorkspace($workspace->id), 404);
        $validated = $request->validate(['workspace_role' => ['required', Rule::in($this->workspaceRoles())]]);
        $currentRole = $user->workspaces()->where('workspaces.id', $workspace->id)->firstOrFail()->pivot->role;
        $this->guardLastWorkspaceOwner($workspace, $user, $currentRole, $validated['workspace_role']);

        $workspace->users()->updateExistingPivot($user->id, ['role' => $validated['workspace_role']]);

        return back()->with('status', 'Workspace権限を更新しました。');
    }

    public function destroyWorkspace(User $user, Workspace $workspace): RedirectResponse
    {
        abort_unless($user->canAccessWorkspace($workspace->id), 404);
        $currentRole = $user->workspaces()->where('workspaces.id', $workspace->id)->firstOrFail()->pivot->role;
        $this->guardLastWorkspaceOwner($workspace, $user, $currentRole, null);

        DB::transaction(function () use ($user, $workspace): void {
            $workspace->users()->detach($user->id);
            $hasOtherWorkspaceInOrganization = $user->workspaces()
                ->where('workspaces.organization_id', $workspace->organization_id)
                ->exists();

            if (! $hasOtherWorkspaceInOrganization) {
                $workspace->organization->users()->detach($user->id);
            }
        });

        return back()->with('status', 'Workspace所属を解除しました。');
    }

    private function validateWorkspaceMembership(Request $request, User $user): array
    {
        return $request->validate([
            'workspace_id' => [
                'required',
                'integer',
                'exists:workspaces,id',
                Rule::unique('workspace_members', 'workspace_id')->where('user_id', $user->id),
            ],
            'workspace_role' => ['required', Rule::in($this->workspaceRoles())],
        ]);
    }

    private function workspaceRoles(): array
    {
        return [WorkspaceMember::ROLE_OWNER, WorkspaceMember::ROLE_ADMIN, WorkspaceMember::ROLE_MEMBER, WorkspaceMember::ROLE_VIEWER];
    }

    private function guardLastWorkspaceOwner(Workspace $workspace, User $user, string $currentRole, ?string $newRole): void
    {
        if ($currentRole !== WorkspaceMember::ROLE_OWNER || $newRole === WorkspaceMember::ROLE_OWNER) {
            return;
        }

        $hasOtherOwner = $workspace->users()
            ->whereKeyNot($user->id)
            ->wherePivot('role', WorkspaceMember::ROLE_OWNER)
            ->exists();

        if (! $hasOtherOwner) {
            throw ValidationException::withMessages(['workspace_role' => 'Workspaceの最後のOwnerは変更・解除できません。']);
        }
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        return $this->uniqueSlug(Str::slug($name) ?: 'organization', fn (string $slug): bool => Organization::where('slug', $slug)->exists());
    }

    private function uniqueWorkspaceSlug(Organization $organization, string $name): string
    {
        return $this->uniqueSlug(Str::slug($name) ?: 'workspace', fn (string $slug): bool => $organization->workspaces()->where('slug', $slug)->exists());
    }

    private function uniqueSlug(string $base, callable $exists): string
    {
        $slug = $base;
        $index = 2;

        while ($exists($slug)) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }
}
