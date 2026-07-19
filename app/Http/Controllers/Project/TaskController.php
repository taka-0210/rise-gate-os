<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TaskController extends Controller
{
    public function show(Project $project, Task $task): View
    {
        $this->authorizeProjectTask($project, $task);

        Gate::authorize('view', $project);
        Gate::authorize('view', $task);

        $task->load(['assignee', 'creator', 'improvement']);

        return view('tasks.show', [
            'project' => $project,
            'task' => $task,
            'statuses' => Task::statuses(),
            'priorities' => Task::priorities(),
            'canEditTask' => Gate::allows('update', $task),
        ]);
    }

    public function edit(Request $request, Project $project, Task $task): View
    {
        $this->authorizeProjectTask($project, $task);

        Gate::authorize('view', $project);
        Gate::authorize('update', $task);

        return view('tasks.edit', [
            'project' => $project,
            'task' => $task,
            'statuses' => Task::statuses(),
            'priorities' => Task::priorities(),
            'assignableUsers' => $this->assignableUsers($project),
            'initiatives' => $this->assignableInitiatives($request, $project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        Gate::authorize('create', [Task::class, $project]);

        Task::create($this->validateTask($request, $project) + [
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('projects.show', $project)->with('status', 'Task created.');
    }

    public function update(Request $request, Project $project, Task $task): RedirectResponse
    {
        $this->authorizeProjectTask($project, $task);

        Gate::authorize('view', $project);
        Gate::authorize('update', $task);

        $validated = $this->validateTask($request, $project);
        $validated['completed_at'] = $validated['status'] === Task::STATUS_DONE
            ? ($task->completed_at ?? now())
            : null;

        $task->update($validated);

        return redirect()->route('projects.tasks.show', [$project, $task])->with('status', 'Taskを更新しました。');
    }

    private function validateTask(Request $request, Project $project): array
    {
        $assignableUserIds = $this->assignableUsers($project)->pluck('id')->all();

        $validated = $request->validate([
            'improvement_id' => [
                'required',
                Rule::exists('improvements', 'id')
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at'),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Task::statuses()))],
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(Task::priorities()))],
            'assigned_to' => ['nullable', Rule::in($assignableUserIds)],
            'due_date' => ['nullable', 'date'],
        ]);

        $improvement = $project->improvements()->findOrFail($validated['improvement_id']);
        if (! empty($validated['due_date']) && $improvement->planned_start_date && $improvement->target_date
            && ($validated['due_date'] < $improvement->planned_start_date->toDateString()
                || $validated['due_date'] > $improvement->target_date->toDateString())) {
            throw ValidationException::withMessages([
                'due_date' => "タスクの期限は、取り組みの予定期間（{$improvement->planned_start_date->format('Y/m/d')}〜{$improvement->target_date->format('Y/m/d')}）内で設定してください。",
            ]);
        }

        return $validated;
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

    private function assignableInitiatives(Request $request, Project $project)
    {
        $member = $project->members()
            ->where('user_id', $request->user()->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first();

        return $project->improvements()
            ->when($member?->project_role === ProjectMember::ROLE_CLIENT, fn ($query) => $query->where('visibility', \App\Models\Improvement::VISIBILITY_CLIENT))
            ->with('roadmap')
            ->orderBy('roadmap_id')
            ->orderBy('roadmap_sort_order')
            ->orderBy('id')
            ->get();
    }

    private function authorizeProjectTask(Project $project, Task $task): void
    {
        abort_unless($task->project_id === $project->id, 404);
    }
}
