<?php

namespace App\Http\Controllers;

use App\Models\Improvement;
use App\Models\OrganizationGroup;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectExecutionController extends Controller
{
    public function index(Request $request, ProjectExecutionAccess $access): View
    {
        $company = $request->attributes->get('currentCompany');
        $projects = Project::query()
            ->where('organization_id', $company->id)
            ->where('execution_contract_version', Project::EXECUTION_CONTRACT)
            ->with(['owner:id,name', 'owningWorkspace:id,name'])
            ->latest('updated_at')->get()
            ->filter(fn (Project $project) => $access->canRead($request->user(), $project))
            ->values();

        return view('project-execution.index', compact('company', 'projects'));
    }

    public function create(Request $request): View
    {
        $company = $request->attributes->get('currentCompany');
        $workspaces = $request->user()->workspaces()->where('workspaces.organization_id', $company->id)
            ->where('workspaces.status', Workspace::STATUS_ACTIVE)->orderBy('workspaces.name')->get();

        return view('project-execution.create', compact('company', 'workspaces'));
    }

    public function store(Request $request, ProjectExecutionWriter $writer): RedirectResponse
    {
        $company = $request->attributes->get('currentCompany');
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:3000'],
            'expected_outcome' => ['required', 'string', 'max:3000'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $workspace = Workspace::query()->where('organization_id', $company->id)
            ->where('status', Workspace::STATUS_ACTIVE)->findOrFail($data['workspace_id']);
        $project = $writer->createProject($request->user(), $workspace, $data);

        return redirect()->route('project-execution.show', $project)->with('status', 'Projectを作成しました。');
    }

    public function show(Request $request, Project $project, ProjectExecutionAccess $access): View
    {
        $this->assertCurrentCompany($request, $project);
        abort_unless($access->canRead($request->user(), $project), 404);
        $project->load([
            'owner:id,name', 'reviewer:id,name',
            'roadmaps' => fn ($query) => $query->select(['id', 'project_id', 'title', 'purpose', 'status', 'sort_order']),
            'roadmaps.improvements' => fn ($query) => $query->select(['id', 'project_id', 'roadmap_id', 'title', 'theme_description', 'execution_status', 'roadmap_sort_order']),
            'roadmaps.improvements.tasks' => fn ($query) => $query->select(['id', 'project_id', 'improvement_id', 'title', 'done_condition', 'assigned_to', 'reviewer_user_id', 'status', 'due_date', 'sort_order'])->with(['assignee:id,name', 'reviewer:id,name']),
            'tasks' => fn ($query) => $query->whereNull('improvement_id')->select(['id', 'project_id', 'improvement_id', 'title', 'done_condition', 'assigned_to', 'reviewer_user_id', 'status', 'due_date', 'sort_order'])->with(['assignee:id,name', 'reviewer:id,name']),
        ]);
        $member = $access->activeExplicitMember($request->user(), $project);

        return view('project-execution.show', [
            'company' => $request->attributes->get('currentCompany'),
            'project' => $project,
            'canManage' => $access->canManageStructure($request->user(), $project),
            'canCreateAction' => $access->canCreateAction($request->user(), $project),
            'isExplicitMember' => $member !== null,
        ]);
    }

    public function manage(Request $request, Project $project, ProjectExecutionAccess $access): View
    {
        $this->assertCurrentCompany($request, $project);
        abort_unless($access->activeExplicitMember($request->user(), $project), 403);
        $project->load(['members.user:id,name,email', 'members.activeRoleAssignments', 'roadmaps.improvements', 'tasks.assignee:id,name', 'tasks.reviewer:id,name']);
        $company = $request->attributes->get('currentCompany');
        $eligibleUsers = User::query()->whereHas('organizationMemberships', fn ($query) => $query
            ->where('organization_id', $company->id)->where('membership_status', 'active'))->where('is_active', true)->orderBy('name')->get();
        $groups = OrganizationGroup::query()->where('organization_id', $company->id)->whereNull('archived_at')
            ->with(['memberships.organizationMembership.user:id,name,email,is_active'])->orderBy('name')->get();
        $workspaces = $request->user()->workspaces()->where('workspaces.organization_id', $company->id)
            ->where('workspaces.status', Workspace::STATUS_ACTIVE)->orderBy('workspaces.name')->get();

        return view('project-execution.manage', compact('company', 'project', 'eligibleUsers', 'groups', 'workspaces'));
    }

    public function updateProject(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate([
            'project_version' => ['required', 'integer'], 'name' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:3000'], 'expected_outcome' => ['required', 'string', 'max:3000'],
            'start_date' => ['nullable', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $writer->updateProject($request->user(), $project, $data, (int) $data['project_version']);

        return back()->with('status', 'Projectを更新しました。');
    }

    public function storeRoadmap(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'title' => ['required', 'string', 'max:150'], 'purpose' => ['nullable', 'string', 'max:2000']]);
        $writer->createRoadmap($request->user(), $project, $data, (int) $data['project_version']);
        return back()->with('status', 'Roadmapを追加しました。');
    }

    public function storeTheme(Request $request, Project $project, Roadmap $roadmap, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000']]);
        $writer->createTheme($request->user(), $project, $roadmap, $data, (int) $data['project_version']);
        return back()->with('status', 'Action Themeを追加しました。');
    }

    public function storeAction(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate([
            'project_version' => ['required', 'integer'], 'improvement_id' => ['nullable', 'integer'],
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:3000'],
            'done_condition' => ['required', 'string', 'max:3000'], 'assigned_to' => ['required', 'integer'],
            'reviewer_user_id' => ['nullable', 'integer', 'different:assigned_to'], 'due_date' => ['nullable', 'date'],
        ]);
        $writer->createAction($request->user(), $project, $data, (int) $data['project_version']);
        return back()->with('status', 'Actionを追加しました。');
    }

    public function transitionAction(Request $request, Project $project, Task $action, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        abort_unless($action->project_id === $project->id, 404);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'command' => ['required', Rule::in(['start', 'complete', 'confirm', 'reject', 'reopen'])], 'reason' => ['nullable', 'string', 'max:1000']]);
        $writer->transitionAction($request->user(), $action, $data['command'], (int) $data['project_version'], $data['reason'] ?? null);
        return back()->with('status', 'Actionの状態を更新しました。');
    }

    public function updateAction(Request $request, Project $project, Task $action, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        abort_unless($action->project_id === $project->id, 404);
        $data = $request->validate([
            'project_version' => ['required', 'integer'], 'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:3000'], 'done_condition' => ['required', 'string', 'max:3000'],
            'due_date' => ['nullable', 'date'], 'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $writer->updateAction($request->user(), $action, $data, (int) $data['project_version'], $data['reason'] ?? null);
        return back()->with('status', 'Action plan was updated.');
    }

    public function moveAction(Request $request, Project $project, Task $action, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        abort_unless($action->project_id === $project->id, 404);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'improvement_id' => ['nullable', 'integer'], 'sort_order' => ['required', 'integer', 'min:1']]);
        $theme = filled($data['improvement_id'] ?? null) ? Improvement::query()->where('project_id', $project->id)->findOrFail($data['improvement_id']) : null;
        $writer->moveAction($request->user(), $action, $theme, (int) $data['sort_order'], (int) $data['project_version']);
        return back()->with('status', 'Action was moved without changing its identity or due date.');
    }

    public function archiveEntity(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'type' => ['required', Rule::in(['project', 'roadmap', 'theme', 'action'])], 'entity_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);
        $entity = match ($data['type']) {
            'project' => $project,
            'roadmap' => Roadmap::query()->where('project_id', $project->id)->findOrFail($data['entity_id']),
            'theme' => Improvement::query()->where('project_id', $project->id)->findOrFail($data['entity_id']),
            'action' => Task::query()->where('project_id', $project->id)->findOrFail($data['entity_id']),
        };
        $writer->archive($request->user(), $entity, (int) $data['project_version'], $data['reason']);
        return $data['type'] === 'project' ? redirect()->route('project-execution.index')->with('status', 'Project was archived.') : back()->with('status', 'Item was archived with history.');
    }

    public function reopenEntity(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'type' => ['required', Rule::in(['roadmap', 'theme', 'action'])], 'entity_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);
        $entity = match ($data['type']) {
            'roadmap' => Roadmap::withTrashed()->where('project_id', $project->id)->findOrFail($data['entity_id']),
            'theme' => Improvement::withTrashed()->where('project_id', $project->id)->findOrFail($data['entity_id']),
            'action' => Task::withTrashed()->where('project_id', $project->id)->findOrFail($data['entity_id']),
        };
        $writer->reopen($request->user(), $entity, $project, (int) $data['project_version'], $data['reason']);
        return back()->with('status', 'Item was reopened with history.');
    }

    public function updateVisibility(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'visibility' => ['required', Rule::in(array_keys(Project::visibilities()))], 'group_ids' => ['sometimes', 'array'], 'group_ids.*' => ['integer'], 'confidential_reason' => ['nullable', 'string', 'max:2000']]);
        $writer->setVisibility($request->user(), $project, $data['visibility'], $data['group_ids'] ?? [], $data['confidential_reason'] ?? null, (int) $data['project_version']);
        return back()->with('status', '公開範囲を更新しました。');
    }

    public function storeMember(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'user_id' => ['required', 'integer'], 'workspace_id' => ['required', 'integer'], 'roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', Rule::in(['member', 'viewer'])]]);
        $writer->addMember($request->user(), $project, User::findOrFail($data['user_id']), Workspace::findOrFail($data['workspace_id']), $data['roles'], (int) $data['project_version']);
        return back()->with('status', '参加者を追加しました。');
    }

    public function destroyMember(Request $request, Project $project, ProjectMember $member, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:1000']]);
        $writer->leaveMember($request->user(), $project, $member, $data['reason'], (int) $data['project_version']);
        return back()->with('status', '参加を終了しました。履歴は保持されます。');
    }

    public function updateReviewer(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'reviewer_user_id' => ['nullable', 'integer']]);
        $reviewer = filled($data['reviewer_user_id'] ?? null) ? User::findOrFail($data['reviewer_user_id']) : null;
        $writer->setProjectReviewer($request->user(), $project, $reviewer, (int) $data['project_version']);
        return back()->with('status', 'Project Reviewerを更新しました。');
    }

    public function transferOwner(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate(['project_version' => ['required', 'integer'], 'owner_user_id' => ['required', 'integer']]);
        $writer->transferOwner($request->user(), $project, User::findOrFail($data['owner_user_id']), (int) $data['project_version']);
        return back()->with('status', 'Ownerを交代しました。');
    }

    public function completeParent(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate([
            'project_version' => ['required', 'integer'],
            'confirmed_project_version' => ['required', 'integer'],
            'type' => ['required', Rule::in(['project', 'roadmap', 'theme'])],
            'entity_id' => ['nullable', 'integer'],
        ]);
        $parent = match ($data['type']) {
            'project' => $project,
            'roadmap' => Roadmap::query()->where('project_id', $project->id)->findOrFail($data['entity_id']),
            'theme' => Improvement::query()->where('project_id', $project->id)->findOrFail($data['entity_id']),
        };
        $writer->completeParent($request->user(), $parent, (int) $data['project_version'], (int) $data['confirmed_project_version']);

        return back()->with('status', 'Completion was recorded without changing child states.');
    }

    public function reviewProject(Request $request, Project $project, ProjectExecutionWriter $writer): RedirectResponse
    {
        $this->assertCurrentCompany($request, $project);
        $data = $request->validate([
            'project_version' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['confirm', 'reject'])],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $writer->reviewProject($request->user(), $project, $data['decision'] === 'confirm', (int) $data['project_version'], $data['reason'] ?? null);

        return back()->with('status', 'Project review was recorded.');
    }

    private function assertCurrentCompany(Request $request, Project $project): void
    {
        abort_unless($project->usesScopeEight() && $project->organization_id === $request->attributes->get('currentCompany')->id, 404);
    }
}
