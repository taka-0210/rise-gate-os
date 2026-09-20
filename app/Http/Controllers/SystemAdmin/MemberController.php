<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountCredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(): View
    {
        return view('system-admin.members.index', [
            'members' => User::query()->with('workspaces.organization')->orderBy('name')->get(),
        ]);
    }

    public function store(): RedirectResponse
    {
        abort(410, 'Staff accounts must be created through an organization invitation.');
    }

    public function edit(User $user): View
    {
        return view('system-admin.members.edit', [
            'member' => $user->load('workspaces.organization'),
            'workspaces' => Workspace::query()->with('organization')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user, AccountCredentialService $credentials): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['prohibited'],
            'password_confirmation' => ['prohibited'],
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

        DB::transaction(function () use ($request, $user, $validated, $credentials): void {
            $oldEmail = $user->email;
            $emailChanged = ! hash_equals($oldEmail, $validated['email']);
            $disabled = $user->is_active && ! $validated['is_active'];

            $user->fill([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'is_system_admin' => $validated['is_system_admin'],
                'is_active' => $validated['is_active'],
            ]);
            if ($emailChanged) {
                $user->email_verified_at = null;
            }
            $user->save();

            if ($emailChanged || $disabled) {
                $credentials->rotate(
                    $user,
                    'account.credentials_changed_by_system_admin',
                    $request->user(),
                    [$oldEmail],
                );
            }
        });

        return redirect()->route('system-admin.members.edit', $user)->with('status', 'メンバー情報を更新しました。');
    }

    public function storeWorkspace(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateWorkspaceMembership($request, $user);
        $workspace = Workspace::query()->with('organization')->findOrFail($validated['workspace_id']);

        DB::transaction(function () use ($user, $workspace, $validated): void {
            OrganizationUser::query()->firstOrCreate(
                [
                    'organization_id' => $workspace->organization_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => OrganizationUser::ROLE_MEMBER,
                    'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
                    'membership_status' => OrganizationUser::STATUS_ACTIVE,
                    'joined_at' => now(),
                ],
            );
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
}
