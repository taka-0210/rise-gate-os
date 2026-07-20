<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\AiProposal;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ScheduleIntegrityService;
use App\Services\RelativeScheduleService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function schedule(Request $request): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        $projects = Project::query()
            ->whereHas('members', function ($query) use ($request, $currentWorkspace): void {
                $query->where('user_id', $request->user()->id)
                    ->where('workspace_id', $currentWorkspace->id)
                    ->where('status', ProjectMember::STATUS_ACTIVE);
            })
            ->with(['client', 'roadmaps.improvements.tasks', 'improvements.tasks'])
            ->withCount(['tasks as open_tasks_count' => fn ($query) => $query->whereNotIn('status', [Task::STATUS_DONE, Task::STATUS_ARCHIVED])])
            ->get();

        $integrityService = app(ScheduleIntegrityService::class);
        $integrity = $projects->mapWithKeys(fn (Project $project) => [$project->id => $integrityService->inspect($project)]);
        $scheduled = $projects->filter(fn (Project $project) => $project->start_date && $project->due_date)
            ->sortBy(fn (Project $project) => [$project->start_date->timestamp, $project->due_date->timestamp, $project->name])
            ->values();
        $unscheduled = $projects->reject(fn (Project $project) => $project->start_date && $project->due_date)
            ->sortBy('name')->values();

        $overlapProjects = $scheduled->mapWithKeys(function (Project $project) use ($scheduled): array {
            $overlaps = $scheduled->filter(fn (Project $other) => $other->id !== $project->id
                && $project->start_date->lte($other->due_date)
                && $project->due_date->gte($other->start_date))->values();

            return [$project->id => $overlaps];
        });
        $overlapCounts = $overlapProjects->map(fn ($overlaps) => $overlaps->count());

        $axisStart = ($scheduled->min(fn (Project $project) => $project->start_date)?->copy() ?? Carbon::today())->subDays(2);
        $axisEnd = ($scheduled->max(fn (Project $project) => $project->due_date)?->copy() ?? Carbon::today()->addDays(28))->addDays(2);
        $axisDays = max(1, $axisStart->diffInDays($axisEnd) + 1);
        $timelineWidth = max(900, $axisDays * ($axisDays > 180 ? 7 : 12));

        $months = collect();
        $monthCursor = $axisStart->copy()->startOfMonth();
        while ($monthCursor->lte($axisEnd)) {
            $segmentStart = $monthCursor->copy()->max($axisStart);
            $segmentEnd = $monthCursor->copy()->endOfMonth()->min($axisEnd);
            $months->push([
                'label' => $segmentStart->format('Y年n月'),
                'left' => $axisStart->diffInDays($segmentStart) / $axisDays * 100,
                'width' => ($segmentStart->diffInDays($segmentEnd) + 1) / $axisDays * 100,
            ]);
            $monthCursor->addMonth();
        }

        $ticks = collect();
        for ($cursor = $axisStart->copy(); $cursor->lte($axisEnd); $cursor->addDays(7)) {
            $ticks->push([
                'label' => $cursor->format('n/j'),
                'left' => $axisStart->diffInDays($cursor) / $axisDays * 100,
            ]);
        }

        return view('projects.schedule', compact(
            'scheduled', 'unscheduled', 'integrity', 'overlapCounts', 'overlapProjects', 'axisStart', 'axisEnd',
            'axisDays', 'timelineWidth', 'months', 'ticks', 'currentWorkspace'
        ));
    }

    public function index(Request $request): View
    {
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        $sort = $request->input('sort', 'latest');
        if (! in_array($sort, ['latest', 'oldest', 'client_asc', 'client_desc'], true)) {
            $sort = 'latest';
        }
        $status = $request->input('status');
        if (! array_key_exists((string) $status, Project::statuses())) {
            $status = null;
        }
        $priority = $request->input('priority');
        if (! array_key_exists((string) $priority, Project::priorities())) {
            $priority = null;
        }
        $filterableClients = $this->filterableClients($request, $currentWorkspace->id);
        $clientId = $request->integer('client_id') ?: null;
        if ($clientId && ! $filterableClients->contains('id', $clientId)) {
            $clientId = null;
        }

        $projects = Project::query()
            ->whereHas('members', function ($query) use ($request, $currentWorkspace): void {
                $query->where('user_id', $request->user()->id)
                    ->where('workspace_id', $currentWorkspace->id)
                    ->where('status', ProjectMember::STATUS_ACTIVE);
            })
            ->when($clientId, fn ($query) => $query->where('client_id', $clientId))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($priority, fn ($query) => $query->where('priority', $priority))
            ->withCount(['roadmaps', 'improvements', 'tasks'])
            ->with(['owner', 'client', 'roadmaps.improvements.tasks', 'improvements.tasks', 'sourceImprovementOutput.improvement.project', 'members' => fn ($query) => $query->where('user_id', $request->user()->id)])
            ->when($sort === 'latest', fn ($query) => $query->latest())
            ->when($sort === 'oldest', fn ($query) => $query->oldest())
            ->when(in_array($sort, ['client_asc', 'client_desc'], true), function ($query) use ($sort): void {
                $query->orderBy(
                    Client::select('name')->whereColumn('clients.id', 'projects.client_id'),
                    $sort === 'client_asc' ? 'asc' : 'desc'
                )->orderBy('projects.name');
            })
            ->paginate(12)
            ->withQueryString();

        $scheduleIntegrity = $projects->getCollection()->mapWithKeys(
            fn (Project $project) => [$project->id => app(ScheduleIntegrityService::class)->inspect($project)]
        );

        return view('projects.index', [
            'projects' => $projects,
            'statuses' => Project::statuses(),
            'priorities' => Project::priorities(),
            'sort' => $sort,
            'clients' => $filterableClients,
            'selectedClientId' => $clientId,
            'selectedStatus' => $status,
            'selectedPriority' => $priority,
            'scheduleIntegrity' => $scheduleIntegrity,
        ]);
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
        $validated = $this->normalizeProjectPeriod($validated);
        $starterMode = $request->validate([
            'starter_mode' => ['nullable', 'string', Rule::in(['blank', 'starter'])],
        ])['starter_mode'] ?? 'starter';

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

        if ($starterMode === 'starter') {
            $defaultRoadmap = Roadmap::create([
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'project_id' => $project->id,
                'title' => 'プロジェクトを前に進める',
                'purpose' => 'Project全体の取り組みを受け止め、実現までの道筋を具体化します。',
                'status' => Roadmap::STATUS_ACTIVE,
                'sort_order' => 1,
                'created_by' => $request->user()->id,
            ]);

            Improvement::create([
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'project_id' => $project->id,
                'roadmap_id' => $defaultRoadmap->id,
                'roadmap_sort_order' => 1,
                'title' => '進めるための具体的な動き',
                'desired_state' => 'このRoadmapを具体的なTaskによって前へ進めます。',
                'status' => Improvement::STATUS_PLANNED,
                'visibility' => Improvement::VISIBILITY_INTERNAL,
                'proposed_by' => $request->user()->id,
                'assigned_to' => $request->user()->id,
            ]);
        }

        return redirect()->route('projects.show', $project);
    }

    public function show(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $currentWorkspaceRole = $request->attributes->get('currentWorkspaceRole');
        $currentMember = $project->members()->where('user_id', $request->user()->id)->where('status', ProjectMember::STATUS_ACTIVE)->first();
        $canViewInternalNotes = $currentMember?->project_role !== ProjectMember::ROLE_CLIENT;

        $project->load(['client', 'owner', 'owningWorkspace', 'billingWorkspace', 'sourceImprovementOutput.improvement.project', 'members.user', 'members.workspace']);
        $aiRequests = $project->aiRequests()->with(['requester', 'proposal', 'attachments'])->limit(10)->get();
        $pendingAiProposals = $project->aiProposals()
            ->where('status', AiProposal::STATUS_PENDING)
            ->latest()
            ->get();
        $pendingAiProposalCount = $pendingAiProposals->count();

        $allImprovements = $project->improvements()
            ->when($currentMember?->project_role === ProjectMember::ROLE_CLIENT, fn ($query) => $query->where('visibility', Improvement::VISIBILITY_CLIENT))
            ->with(['proposer', 'assignee', 'roadmap'])
            ->latest()
            ->get();
        $improvements = $allImprovements->take(6);

        $allTasks = $project->tasks()
            ->with(['assignee', 'improvement'])
            ->latest()
            ->get();
        $tasks = $allTasks->take(8);

        $visibleImprovementScope = function ($query) use ($currentMember): void {
            if ($currentMember?->project_role === ProjectMember::ROLE_CLIENT) {
                $query->where('visibility', Improvement::VISIBILITY_CLIENT);
            }
        };

        $roadmaps = $project->roadmaps()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['improvements' => function ($query) use ($visibleImprovementScope): void {
                $visibleImprovementScope($query);
                $query->with(['assignee', 'proposer', 'tasks.assignee']);
            }])
            ->get();
        $projectTimeline = $this->buildProjectTimeline($project, $allTasks, $allImprovements, $roadmaps);
        $scheduleIntegrity = app(ScheduleIntegrityService::class)->inspect($project);

        $unclassifiedImprovements = $project->improvements()
            ->whereNull('roadmap_id')
            ->tap($visibleImprovementScope)
            ->with(['assignee', 'proposer'])
            ->latest()
            ->get();

        $canManageMembers = Gate::allows('manageMembers', [$project, $currentWorkspaceRole]);
        [$memberPreview, $memberPreviewError] = $this->memberPreview($request, $project, $canManageMembers);

        return view('projects.work-view', [
            'project' => $project,
            'statuses' => Project::statuses(),
            'priorities' => Project::priorities(),
            'roles' => ProjectMember::roles(),
            'permissions' => ProjectMember::permissions(),
            'improvements' => $improvements,
            'allImprovements' => $allImprovements,
            'improvementStatuses' => Improvement::statuses(),
            'improvementVisibilities' => Improvement::visibilities(),
            'tasks' => $tasks,
            'allTasks' => $allTasks,
            'taskStatuses' => Task::statuses(),
            'taskPriorities' => Task::priorities(),
            'roadmaps' => $roadmaps,
            'roadmapStatuses' => Roadmap::statuses(),
            'projectTimeline' => $projectTimeline,
            'unclassifiedImprovements' => $unclassifiedImprovements,
            'assignableUsers' => $this->assignableUsers($project),
            'canCreateTask' => Gate::allows('create', [Task::class, $project]),
            'canCreateImprovement' => Gate::allows('create', [Improvement::class, $project]),
            'canCreateRoadmap' => Gate::allows('create', [Roadmap::class, $project]),
            'canEditProject' => Gate::allows('update', $project),
            'canManageMembers' => $canManageMembers,
            'memberPreview' => $memberPreview,
            'memberPreviewError' => $memberPreviewError,
            'aiRequests' => $aiRequests,
            'pendingAiProposalCount' => $pendingAiProposalCount,
            'pendingAiProposals' => $pendingAiProposals,
            'scheduleIntegrity' => $scheduleIntegrity,
            'internalNotes' => $canViewInternalNotes ? $project->internalNotes()->with(['user', 'attachments', 'references'])->limit(50)->get() : collect(),
            'canViewInternalNotes' => $canViewInternalNotes,
            'canCreateInternalNote' => $canViewInternalNotes && Gate::allows('update', $project),
        ]);
    }

    public function clientPlan(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $showTasks = $request->boolean('show_tasks', true);
        $showProgress = $request->boolean('show_progress', false);

        $project->load(['client', 'owner', 'owningWorkspace.businessProfile', 'owningWorkspace.bankAccounts']);
        $roadmaps = $project->roadmaps()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['improvements' => function ($query): void {
                $query->orderBy('roadmap_sort_order')->orderBy('id')->with(['tasks' => fn ($tasks) => $tasks->orderBy('planned_start_date')->orderBy('due_date')->orderBy('id')]);
            }])
            ->get();

        $unclassifiedImprovements = $project->improvements()
            ->whereNull('roadmap_id')
            ->orderBy('planned_start_date')
            ->orderBy('id')
            ->with(['tasks' => fn ($tasks) => $tasks->orderBy('planned_start_date')->orderBy('due_date')->orderBy('id')])
            ->get();

        $visibleImprovements = $roadmaps->flatMap->improvements->concat($unclassifiedImprovements);
        $visibleTasks = $visibleImprovements->flatMap->tasks;

        return view('projects.client-plan', [
            'project' => $project,
            'roadmaps' => $roadmaps,
            'unclassifiedImprovements' => $unclassifiedImprovements,
            'visibleImprovements' => $visibleImprovements,
            'visibleTasks' => $visibleTasks,
            'taskStatuses' => Task::statuses(),
            'improvementStatuses' => Improvement::statuses(),
            'roadmapStatuses' => Roadmap::statuses(),
            'documentOptions' => [
                'show_tasks' => $showTasks,
                'show_progress' => $showProgress,
                'version' => trim((string) $request->input('version', '1.0')),
                'prepared_by' => trim((string) $request->input('prepared_by', $project->owner?->name ?? '')),
                'prepared_on' => $request->date('prepared_on')?->toDateString() ?? now()->toDateString(),
            ],
        ]);
    }

    public function legacy(Request $request, Project $project): View
    {
        return view('projects.show', $this->show($request, $project)->getData());
    }

    private function buildProjectTimeline(Project $project, Collection $tasks, Collection $improvements, Collection $roadmaps): Collection
    {
        $events = collect();

        if ($project->start_date) {
            $events->push([
                'date' => $project->start_date->copy()->startOfDay(),
                'type' => 'Project',
                'title' => 'Projectを開始',
                'description' => $project->name.'の取り組みを開始しました。',
                'url' => null,
                'delayed' => false,
            ]);
        }

        foreach ($tasks as $task) {
            if (! $task->completed_at) {
                continue;
            }

            $completedDate = $task->completed_at->copy();
            $delayed = $task->due_date && $completedDate->toDateString() > $task->due_date->toDateString();
            $delayedDays = $task->due_date
                ? (int) $task->due_date->copy()->startOfDay()->diffInDays($completedDate->copy()->startOfDay())
                : 0;
            $description = $delayed
                ? '期限の'.$task->due_date->format('Y年n月j日').'から'.$delayedDays.'日遅れて完了しました。'
                : ($task->due_date ? '期限どおりに完了しました。' : '作業を完了しました。');

            $events->push([
                'date' => $completedDate,
                'type' => 'Task',
                'title' => $task->title,
                'description' => $description,
                'url' => route('projects.tasks.show', [$project, $task]),
                'delayed' => $delayed,
            ]);
        }

        foreach ($improvements as $improvement) {
            $implemented = (bool) $improvement->implemented_at;
            $events->push([
                'date' => ($improvement->implemented_at ?? $improvement->created_at)->copy(),
                'type' => '改善',
                'title' => $improvement->title,
                'description' => $implemented ? '改善を実施しました。' : '改善として記録しました。',
                'url' => route('projects.improvements.show', [$project, $improvement]),
                'delayed' => false,
            ]);
        }

        foreach ($roadmaps as $roadmap) {
            $events->push([
                'date' => $roadmap->created_at->copy(),
                'type' => 'Roadmap',
                'title' => $roadmap->title,
                'description' => 'これから目指すテーマとしてRoadmapへ追加しました。',
                'url' => route('projects.show', $project).'#roadmaps',
                'delayed' => false,
            ]);
        }

        if ($project->completed_at) {
            $events->push([
                'date' => $project->completed_at->copy(),
                'type' => 'Project',
                'title' => 'Projectがひと区切り',
                'description' => 'ここまでの取り組みを完了し、次の改善へつなげます。',
                'url' => null,
                'delayed' => $project->due_date && $project->completed_at->toDateString() > $project->due_date->toDateString(),
            ]);
        }

        return $events
            ->sortBy(fn (array $event) => $event['date']->format('Y-m-d H:i:s'))
            ->values();
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
            'movableWorkspaces' => $this->movableWorkspaces($request, $project),
            'canMoveProject' => Gate::allows('move', $project),
        ]);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        Gate::authorize('update', $project);

        $validated = $this->validateProject($request, $project->owning_workspace_id);
        $validated = $this->normalizeProjectPeriod($validated);
        if (! empty($validated['start_date']) && ! empty($validated['due_date'])) {
            $count = $project->roadmaps()
                ->whereNotNull('planned_start_date')
                ->whereNotNull('target_date')
                ->where(fn ($query) => $query
                    ->whereDate('planned_start_date', '<', $validated['start_date'])
                    ->orWhereDate('target_date', '>', $validated['due_date']))
                ->count();
            if ($count > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'start_date' => "この変更により、ロードマップ{$count}件がProjectの期間外になります。先にロードマップの日程を調整してください。",
                ]);
            }
        }
        $startWasUnset = ! $project->start_date;
        $project->update($validated);
        if ($startWasUnset && $project->start_date) {
            app(RelativeScheduleService::class)->anchor($project->fresh());
        }

        return redirect()->route('projects.show', $project)->with('status', 'Projectを更新しました。');
    }

    public function move(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('move', $project);

        $validated = $request->validate([
            'destination_workspace_id' => ['required', 'integer'],
            'destination_client_id' => ['required', 'integer'],
        ]);
        $destination = $this->movableWorkspaces($request, $project)
            ->firstWhere('id', (int) $validated['destination_workspace_id']);

        if (! $destination) {
            abort(403);
        }
        $destinationClient = $destination->clients->firstWhere('id', (int) $validated['destination_client_id']);
        if (! $destinationClient) {
            return back()->withErrors(['destination_client_id' => '移動先Workspaceのクライアントを選択してください。']);
        }

        DB::transaction(function () use ($project, $destination, $destinationClient, $request): void {
            $project->update([
                'organization_id' => $destination->organization_id,
                'owning_workspace_id' => $destination->id,
                'billing_workspace_id' => $destination->id,
                'client_id' => $destinationClient->id,
            ]);
            Improvement::withTrashed()->where('project_id', $project->id)->update([
                'organization_id' => $destination->organization_id,
                'workspace_id' => $destination->id,
            ]);
            Task::withTrashed()->where('project_id', $project->id)->update([
                'organization_id' => $destination->organization_id,
                'workspace_id' => $destination->id,
            ]);
            Roadmap::withTrashed()->where('project_id', $project->id)->update([
                'organization_id' => $destination->organization_id,
                'workspace_id' => $destination->id,
            ]);
            $project->members()
                ->where('user_id', $request->user()->id)
                ->update(['workspace_id' => $destination->id]);
        });

        $request->session()->put('current_workspace_id', $destination->id);

        return redirect()->route('projects.show', $project)->with('status', 'Projectを'.$destination->name.'へ移動しました。');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $request->validate([
            'delete_password' => ['required', 'current_password'],
        ], [
            'delete_password.required' => '削除パスワードを入力してください。',
            'delete_password.current_password' => '削除パスワードが正しくありません。',
        ]);

        $projectName = $project->name;
        $project->delete();

        return redirect()->route('projects.index')->with('status', $projectName.'を削除しました。');
    }

    private function validateProject(Request $request, int $workspaceId): array
    {
        return $request->validate([
            'client_id' => ['required', Rule::exists('clients', 'id')->where('workspace_id', $workspaceId)],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:80'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'current_state' => ['nullable', 'string', 'max:5000'],
            'desired_future_state' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Project::statuses()))],
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(Project::priorities()))],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);
    }

    private function normalizeProjectPeriod(array $attributes): array
    {
        if (empty($attributes['duration_days']) && empty($attributes['start_date'])) {
            $attributes['duration_days'] = 30;
        }

        if (! empty($attributes['start_date']) && ! empty($attributes['duration_days'])) {
            $attributes['due_date'] = Carbon::parse($attributes['start_date'])
                ->addDays((int) $attributes['duration_days'] - 1)
                ->toDateString();
        } elseif (! empty($attributes['start_date']) && ! empty($attributes['due_date'])) {
            $attributes['duration_days'] = Carbon::parse($attributes['start_date'])->diffInDays(Carbon::parse($attributes['due_date'])) + 1;
        } elseif (empty($attributes['start_date'])) {
            $attributes['due_date'] = null;
        }

        return $attributes;
    }

    private function workspaceClients(int $workspaceId)
    {
        return Client::query()->where('workspace_id', $workspaceId)->orderBy('name')->get();
    }

    private function filterableClients(Request $request, int $workspaceId)
    {
        return Client::query()
            ->where('workspace_id', $workspaceId)
            ->whereHas('projects.members', function ($query) use ($request, $workspaceId): void {
                $query->where('user_id', $request->user()->id)
                    ->where('workspace_id', $workspaceId)
                    ->where('status', ProjectMember::STATUS_ACTIVE);
            })
            ->orderBy('name')
            ->get();
    }

    private function movableWorkspaces(Request $request, Project $project)
    {
        return $request->user()->workspaces()
            ->where('workspaces.status', Workspace::STATUS_ACTIVE)
            ->where('workspaces.id', '!=', $project->owning_workspace_id)
            ->wherePivotIn('role', ['owner', 'admin'])
            ->with(['organization', 'clients' => fn ($query) => $query->orderBy('name')])
            ->orderBy('workspaces.name')
            ->get();
    }

    private function assignableUsers(Project $project)
    {
        return User::query()
            ->whereHas('projectMemberships', function ($query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->where('status', ProjectMember::STATUS_ACTIVE);
            })
            ->orderBy('name')
            ->get();
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

        $workspace = $user->workspaces()
            ->where('workspaces.status', Workspace::STATUS_ACTIVE)
            ->orderBy('workspaces.name')
            ->first();
        if (! $workspace) {
            return [null, 'このユーザーはWorkspaceに所属していません。'];
        }

        return [['user' => $user, 'workspace' => $workspace, 'project_role' => $role, 'permission_level' => $permission], null];
    }
}
