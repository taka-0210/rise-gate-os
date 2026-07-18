<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\Improvement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with('organization')
            ->withCount([
                'clients',
                'projects',
                'improvements',
                'improvements as open_improvements_count' => fn ($query) => $query->whereIn('status', [
                    Improvement::STATUS_PROPOSED,
                    Improvement::STATUS_PLANNED,
                    Improvement::STATUS_IN_PROGRESS,
                ]),
                'improvements as recent_improvements_count' => fn ($query) => $query->where('created_at', '>=', now()->startOfWeek()),
            ])
            ->orderBy('workspaces.name')
            ->get();

        return view('workspaces.index', [
            'workspaces' => $workspaces,
            'currentWorkspaceId' => $request->session()->get('current_workspace_id'),
        ]);
    }

    public function create(): View
    {
        return view('workspaces.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'workspace_name' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
        ]);
        $user = $request->user();
        $hasOwnedWorkspace = $user->ownedWorkspaces()->exists();

        $workspace = DB::transaction(function () use ($validated, $user, $hasOwnedWorkspace): Workspace {
            $organization = Organization::create([
                'name' => $validated['organization_name'],
                'slug' => $this->uniqueOrganizationSlug($validated['organization_name']),
            ]);
            $workspace = Workspace::create([
                'organization_id' => $organization->id,
                'owner_user_id' => $user->id,
                'name' => $validated['workspace_name'],
                'slug' => $this->uniqueWorkspaceSlug($organization, $validated['workspace_name']),
                'billing_type' => $hasOwnedWorkspace ? Workspace::BILLING_ADDITIONAL : Workspace::BILLING_INCLUDED,
                'status' => $hasOwnedWorkspace ? Workspace::STATUS_PENDING : Workspace::STATUS_ACTIVE,
                'purpose' => $validated['purpose'] ?? null,
            ]);
            $organization->users()->attach($user->id, ['role' => OrganizationUser::ROLE_OWNER, 'joined_at' => now()]);
            $workspace->users()->attach($user->id, ['role' => WorkspaceMember::ROLE_OWNER, 'joined_at' => now()]);

            return $workspace;
        });

        if ($workspace->status === Workspace::STATUS_ACTIVE) {
            $request->session()->put('current_workspace_id', $workspace->id);
            return redirect()->route('dashboard')->with('status', '基本Workspaceを作成しました。');
        }

        return redirect()->route('workspaces.index')->with('status', '追加Workspaceを申請しました。System Adminの承認後に利用できます。');
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->selectWorkspace($request, $workspace);

        return redirect()->route('dashboard');
    }

    public function projects(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->selectWorkspace($request, $workspace);

        return redirect()->route('projects.index');
    }

    public function edit(Request $request, Workspace $workspace): View
    {
        $this->authorizeManagement($request, $workspace);

        return view('workspaces.edit', ['workspace' => $workspace->load('organization')]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorizeManagement($request, $workspace);
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $workspace->update(['name' => $validated['name']]);

        return redirect()->route('workspaces.edit', $workspace)->with('status', 'Workspace名を更新しました。');
    }

    private function authorizeManagement(Request $request, Workspace $workspace): void
    {
        $membership = $request->user()->workspaces()->where('workspaces.id', $workspace->id)->first();

        abort_unless($membership && in_array($membership->pivot->role, [
            WorkspaceMember::ROLE_OWNER,
            WorkspaceMember::ROLE_ADMIN,
        ], true), 403);
    }

    private function selectWorkspace(Request $request, Workspace $workspace): void
    {
        abort_unless($request->user()->canAccessWorkspace($workspace->id), 403);

        $request->session()->put('current_workspace_id', $workspace->id);
        $request->session()->put('access_mode', 'workspace');
    }

    private function uniqueOrganizationSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;
        $index = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }

    private function uniqueWorkspaceSlug(Organization $organization, string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $index = 2;

        while ($organization->workspaces()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$index++;
        }

        return $slug;
    }
}
