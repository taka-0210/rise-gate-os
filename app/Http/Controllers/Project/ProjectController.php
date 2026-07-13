<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Request $request): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');

        $projects = Project::query()
            ->whereHas('members', function ($query) use ($request, $currentWorkspace): void {
                $query->where('user_id', $request->user()->id)
                    ->where('workspace_id', $currentWorkspace->id)
                    ->where('status', ProjectMember::STATUS_ACTIVE);
            })
            ->with(['owner', 'client', 'members' => fn ($query) => $query->where('user_id', $request->user()->id)])
            ->latest()
            ->paginate(12);

        return view('projects.index', ['projects' => $projects, 'statuses' => Project::statuses(), 'priorities' => Project::priorities()]);
    }

    public function create(Request $request): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        Gate::authorize('create', [Project::class, $currentWorkspace]);

        return view('projects.create', [
            'statuses' => Project::statuses(),
            'priorities' => Project::priorities(),
            'clients' => $this->workspaceClients($currentWorkspace->id),
            'selectedClientId' => $request->integer('client_id') ?: null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        Gate::authorize('create', [Project::class, $currentWorkspace]);

        $validated = $this->validateProject($request, $currentWorkspace->id);

        $project = Project::create($validated + [
            'organization_id' => $currentWorkspace->organization_id,
            'owning_workspace_id' => $currentWorkspace->id,
            'billing_workspace_id' => $currentWorkspace->id,
            'owner_user_id' => $request->user()->id,
        ]);

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $request->user()->id,
            'workspace_id' => $currentWorkspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'invited_by' => $request->user()->id,
            'invited_at' => now(),
            'accepted_at' => now(),
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        return redirect()->route('projects.show', $project);
    }

    public function show(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $currentWorkspaceRole = $request->attributes->get('currentWorkspaceRole');
        $currentMember = $project->members()->where('user_id', $request->user()->id)->where('status', ProjectMember::STATUS_ACTIVE)->first();

        $project->load(['client', 'owner', 'owningWorkspace', 'billingWorkspace', 'members.user', 'members.workspace']);

        $improvements = $project->improvements()
            ->when($currentMember?->project_role === ProjectMember::ROLE_CLIENT, fn ($query) => $query->where('visibility', Improvement::VISIBILITY_CLIENT))
            ->with(['proposer', 'assignee'])
            ->latest()
            ->limit(6)
            ->get();

        $canManageMembers = Gate::allows('manageMembers', [$project, $currentWorkspaceRole]);
        [$memberPreview, $memberPreviewError] = $this->memberPreview($request, $project, $canManageMembers);

        return view('projects.show', [
            'project' => $project,
            'statuses' => Project::statuses(),
            'priorities' => Project::priorities(),
            'roles' => ProjectMember::roles(),
            'permissions' => ProjectMember::permissions(),
            'improvements' => $improvements,
            'improvementStatuses' => Improvement::statuses(),
            'improvementVisibilities' => Improvement::visibilities(),
            'canCreateImprovement' => Gate::allows('create', [Improvement::class, $project]),
            'canEditProject' => Gate::allows('update', $project),
            'canManageMembers' => $canManageMembers,
            'memberPreview' => $memberPreview,
            'memberPreviewError' => $memberPreviewError,
        ]);
    }

    public function edit(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);
        Gate::authorize('update', $project);

        return view('projects.edit', [
            'project' => $project,
            'statuses' => Project::statuses(),
            'priorities' => Project::priorities(),
            'clients' => $this->workspaceClients($project->owning_workspace_id),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        Gate::authorize('update', $project);

        $project->update($this->validateProject($request, $project->owning_workspace_id));

        return redirect()->route('projects.show', $project)->with('status', 'Projectを更新しました。');
    }

    private function validateProject(Request $request, int $workspaceId): array
    {
        return $request->validate([
            'client_id' => ['nullable', Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Project::statuses()))],
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(Project::priorities()))],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
    }

    private function workspaceClients(int $workspaceId)
    {
        return Client::query()->where('workspace_id', $workspaceId)->orderBy('name')->get();
    }

    private function memberPreview(Request $request, Project $project, bool $canManageMembers): array
    {
        if (! $canManageMembers || ! $request->filled('member_email')) {
            return [null, null];
        }

        $role = $request->input('project_role', ProjectMember::ROLE_VIEWER);
        $permission = $request->input('permission_level', ProjectMember::PERMISSION_VIEW);

        if (! array_key_exists($role, ProjectMember::roles())) {
            $role = ProjectMember::ROLE_VIEWER;
        }
        if (! array_key_exists($permission, ProjectMember::permissions())) {
            $permission = ProjectMember::PERMISSION_VIEW;
        }

        $user = User::where('email', $request->input('member_email'))->first();
        if (! $user) {
            return [null, '登録済みユーザーが見つかりません。未登録ユーザー招待はPhase 2以降で対応します。'];
        }
        if ($project->members()->where('user_id', $user->id)->exists()) {
            return [null, 'このユーザーはすでにProjectに参加しています。'];
        }

        $workspace = $user->workspaces()->orderBy('workspaces.name')->first();
        if (! $workspace) {
            return [null, 'このユーザーはWorkspaceに所属していません。'];
        }

        return [['user' => $user, 'workspace' => $workspace, 'project_role' => $role, 'permission_level' => $permission], null];
    }
}
