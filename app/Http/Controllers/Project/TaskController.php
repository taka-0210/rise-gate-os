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

class TaskController extends Controller
{
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

    private function validateTask(Request $request, Project $project): array
    {
        $assignableUserIds = $this->assignableUsers($project)->pluck('id')->all();

        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Task::statuses()))],
            'priority' => ['required', 'string', 'in:'.implode(',', array_keys(Task::priorities()))],
            'assigned_to' => ['nullable', Rule::in($assignableUserIds)],
            'due_date' => ['nullable', 'date'],
        ]);
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
}