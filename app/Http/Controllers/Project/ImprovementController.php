<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Improvement;
use App\Models\ImprovementOutput;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ImprovementController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $improvements = $this->visibleImprovementsQuery($request, $project)
            ->with(['proposer', 'assignee', 'implementer', 'tasks'])
            ->latest()
            ->paginate(12);

        return view('improvements.index', [
            'project' => $project,
            'improvements' => $improvements,
            'visibilities' => Improvement::visibilities(),
            'canCreateImprovement' => Gate::allows('create', [Improvement::class, $project]),
        ]);
    }

    public function create(Project $project): View
    {
        Gate::authorize('view', $project);
        Gate::authorize('create', [Improvement::class, $project]);

        return view('improvements.create', [
            'project' => $project,
            'visibilities' => Improvement::visibilities(),
            'assignableUsers' => $this->assignableUsers($project),
            'roadmaps' => $project->roadmaps()->orderBy('sort_order')->orderBy('id')->get(),
            'selectedRoadmapId' => request()->integer('roadmap') ?: null,
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        Gate::authorize('create', [Improvement::class, $project]);

        $validated = $this->validateImprovement($request, $project);
        $validated['roadmap_sort_order'] = (int) Improvement::where('roadmap_id', $validated['roadmap_id'])->max('roadmap_sort_order') + 1;

        $improvement = Improvement::create($validated + [
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'proposed_by' => $request->user()->id,
        ]);

        return redirect()->route('projects.improvements.show', [$project, $improvement]);
    }

    public function show(Request $request, Project $project, Improvement $improvement): View
    {
        $this->authorizeProjectImprovement($project, $improvement);

        Gate::authorize('view', $project);
        Gate::authorize('view', $improvement);

        $improvement->load(['proposer', 'assignee', 'implementer', 'outputs', 'tasks']);

        $taskOutputIds = $improvement->outputs
            ->where('output_type', ImprovementOutput::TYPE_TASK)
            ->pluck('output_id')
            ->all();
        $projectOutputIds = $improvement->outputs
            ->where('output_type', ImprovementOutput::TYPE_PROJECT)
            ->pluck('output_id')
            ->all();

        return view('improvements.show', [
            'project' => $project,
            'improvement' => $improvement,
            'visibilities' => Improvement::visibilities(),
            'taskOutputs' => Task::query()->whereIn('id', $taskOutputIds)->with(['assignee'])->latest()->get(),
            'projectOutputs' => Project::query()->whereIn('id', $projectOutputIds)->latest()->get(),
            'taskStatuses' => Task::statuses(),
            'taskPriorities' => Task::priorities(),
            'projectStatuses' => Project::statuses(),
            'projectPriorities' => Project::priorities(),
            'assignableUsers' => $this->assignableUsers($project),
            'roadmaps' => $project->roadmaps()->orderBy('sort_order')->orderBy('id')->get(),
            'canCreateTaskOutput' => Gate::allows('create', [Task::class, $project]),
            'canCreateProjectOutput' => Gate::allows('update', $improvement) && Gate::allows('create', [Project::class, $project->owningWorkspace]),
            'canEditImprovement' => Gate::allows('update', $improvement),
        ]);
    }

    public function edit(Project $project, Improvement $improvement): View
    {
        $this->authorizeProjectImprovement($project, $improvement);

        Gate::authorize('view', $project);
        Gate::authorize('update', $improvement);

        return view('improvements.edit', [
            'project' => $project,
            'improvement' => $improvement,
            'visibilities' => Improvement::visibilities(),
            'assignableUsers' => $this->assignableUsers($project),
            'roadmaps' => $project->roadmaps()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, Project $project, Improvement $improvement): RedirectResponse
    {
        $this->authorizeProjectImprovement($project, $improvement);

        Gate::authorize('view', $project);
        Gate::authorize('update', $improvement);

        $validated = $this->validateImprovement($request, $project);
        if ($improvement->roadmap_id !== (int) $validated['roadmap_id']) {
            $validated['roadmap_sort_order'] = (int) Improvement::where('roadmap_id', $validated['roadmap_id'])->max('roadmap_sort_order') + 1;
        }
        $improvement->update($validated);

        return redirect()->route('projects.improvements.show', [$project, $improvement])->with('status', '改善を更新しました。');
    }

    public function destroy(Project $project, Improvement $improvement): RedirectResponse
    {
        $this->authorizeProjectImprovement($project, $improvement);
        Gate::authorize('delete', $improvement);
        if ($improvement->tasks()->exists()) {
            return back()->withErrors(['delete' => '配下にタスクがあるため削除できません。先にタスクを削除してください。']);
        }
        $improvement->delete();

        return redirect()->route('projects.show', $project)->with('status', '取り組みを削除しました。');
    }

    protected function visibleImprovementsQuery(Request $request, Project $project)
    {
        $member = $project->members()
            ->where('user_id', $request->user()->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first();

        $query = $project->improvements();

        if ($member?->project_role === ProjectMember::ROLE_CLIENT) {
            $query->where('visibility', Improvement::VISIBILITY_CLIENT);
        }

        return $query;
    }

    protected function assignableUsers(Project $project)
    {
        return User::query()
            ->whereHas('projectMemberships', function ($query) use ($project): void {
                $query->where('project_id', $project->id)
                    ->where('status', ProjectMember::STATUS_ACTIVE);
            })
            ->orderBy('name')
            ->get();
    }

    private function validateImprovement(Request $request, Project $project): array
    {
        $assignableUserIds = $this->assignableUsers($project)->pluck('id')->all();

        $validated = $request->validate([
            'roadmap_id' => [
                'required',
                Rule::exists('roadmaps', 'id')
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'current_state' => ['nullable', 'string', 'max:10000'],
            'desired_state' => ['nullable', 'string', 'max:10000'],
            'problem' => ['nullable', 'string', 'max:10000'],
            'hypothesis' => ['nullable', 'string', 'max:10000'],
            'action' => ['nullable', 'string', 'max:10000'],
            'result' => ['nullable', 'string', 'max:10000'],
            'impact' => ['nullable', 'string', 'max:10000'],
            'next_action' => ['nullable', 'string', 'max:10000'],
            'planned_effort_days' => ['nullable', 'numeric', 'min:0.25', 'max:999.99'],
            'planned_start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'planned_start_day' => ['nullable', 'integer', 'min:1', 'lte:target_day'],
            'target_day' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'completed_at' => ['nullable', 'date'],
            'visibility' => ['required', 'string', 'in:'.implode(',', array_keys(Improvement::visibilities()))],
            'assigned_to' => ['nullable', Rule::in($assignableUserIds)],
            'implemented_by' => ['nullable', Rule::in($assignableUserIds)],
            'implemented_at' => ['nullable', 'date'],
        ]);

        $roadmap = $project->roadmaps()->findOrFail($validated['roadmap_id']);
        if (! empty($validated['planned_start_date']) && ! empty($validated['target_date'])
            && $roadmap->planned_start_date && $roadmap->target_date
            && ($validated['planned_start_date'] < $roadmap->planned_start_date->toDateString()
                || $validated['target_date'] > $roadmap->target_date->toDateString())) {
            throw ValidationException::withMessages([
                'planned_start_date' => "取り組みの期間は、ロードマップの予定期間（{$roadmap->planned_start_date->format('Y/m/d')}〜{$roadmap->target_date->format('Y/m/d')}）内で設定してください。",
            ]);
        }

        if (! $project->start_date && ! empty($validated['planned_start_day']) && ! empty($validated['target_day'])) {
            if ($validated['planned_start_day'] < ($roadmap->planned_start_day ?? 1)
                || $validated['target_day'] > ($roadmap->target_day ?? $project->duration_days)) {
                throw ValidationException::withMessages(['planned_start_day' => '取り組み期間は所属ロードマップの期間内で設定してください。']);
            }
        }

        if ($request->route('improvement') && ! empty($validated['planned_start_date']) && ! empty($validated['target_date'])) {
            $count = $request->route('improvement')->tasks()
                ->whereNotNull('due_date')
                ->where(fn ($query) => $query
                    ->whereDate('due_date', '<', $validated['planned_start_date'])
                    ->orWhereDate('due_date', '>', $validated['target_date']))
                ->count();
            if ($count > 0) {
                throw ValidationException::withMessages([
                    'planned_start_date' => "この変更により、タスク{$count}件が取り組みの期間外になります。先にタスクの期限を調整してください。",
                ]);
            }
        }
        if ($request->route('improvement') && ! empty($validated['planned_start_day']) && ! empty($validated['target_day'])) {
            $count = $request->route('improvement')->tasks()->where(fn ($query) => $query
                ->where('planned_start_day', '<', $validated['planned_start_day'])
                ->orWhere('due_day', '>', $validated['target_day']))->count();
            if ($count > 0) {
                throw ValidationException::withMessages(['planned_start_day' => "この変更により、タスク{$count}件が取り組み期間外になります。"]);
            }
        }

        return $validated;
    }

    private function authorizeProjectImprovement(Project $project, Improvement $improvement): void
    {
        abort_unless($improvement->project_id === $project->id, 404);
    }
}
