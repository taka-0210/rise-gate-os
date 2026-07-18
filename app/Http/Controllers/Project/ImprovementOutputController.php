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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ImprovementOutputController extends Controller
{
    public function storeTask(Request $request, Project $project, Improvement $improvement): RedirectResponse
    {
        $this->authorizeProjectImprovement($project, $improvement);
        Gate::authorize('view', $project);
        Gate::authorize('create', [Task::class, $project]);

        $task = DB::transaction(function () use ($request, $project, $improvement): Task {
            $task = Task::create($this->validateTask($request, $project) + [
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'project_id' => $project->id,
                'improvement_id' => $improvement->id,
                'created_by' => $request->user()->id,
            ]);

            ImprovementOutput::create([
                'improvement_id' => $improvement->id,
                'output_type' => ImprovementOutput::TYPE_TASK,
                'output_id' => $task->id,
                'created_by' => $request->user()->id,
            ]);

            return $task;
        });

        return redirect()->route('projects.improvements.show', [$project, $improvement])->with('status', 'Task output created.');
    }

    public function storeProject(Request $request, Project $project, Improvement $improvement): RedirectResponse
    {
        $this->authorizeProjectImprovement($project, $improvement);
        Gate::authorize('view', $project);
        Gate::authorize('update', $improvement);
        Gate::authorize('create', [Project::class, $project->owningWorkspace]);

        $newProject = DB::transaction(function () use ($request, $project, $improvement): Project {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'summary' => ['nullable', 'string', 'max:5000'],
                'status' => ['required', 'string', 'in:'.implode(',', array_keys(Project::statuses()))],
                'priority' => ['required', 'string', 'in:'.implode(',', array_keys(Project::priorities()))],
                'due_date' => ['nullable', 'date'],
            ]);

            $newProject = Project::create($validated + [
                'organization_id' => $project->organization_id,
                'owning_workspace_id' => $project->owning_workspace_id,
                'billing_workspace_id' => $project->billing_workspace_id,
                'client_id' => $project->client_id,
                'owner_user_id' => $request->user()->id,
            ]);

            ProjectMember::create([
                'project_id' => $newProject->id,
                'user_id' => $request->user()->id,
                'workspace_id' => $project->owning_workspace_id,
                'project_role' => ProjectMember::ROLE_OWNER,
                'permission_level' => ProjectMember::PERMISSION_ADMIN,
                'invited_by' => $request->user()->id,
                'invited_at' => now(),
                'accepted_at' => now(),
                'status' => ProjectMember::STATUS_ACTIVE,
            ]);

            $defaultRoadmap = Roadmap::create([
                'organization_id' => $newProject->organization_id,
                'workspace_id' => $newProject->owning_workspace_id,
                'project_id' => $newProject->id,
                'title' => 'プロジェクトを前に進める',
                'purpose' => 'Project全体の取り組みを受け止め、実現までの道筋を具体化します。',
                'status' => Roadmap::STATUS_ACTIVE,
                'sort_order' => 1,
                'created_by' => $request->user()->id,
            ]);

            Improvement::create([
                'organization_id' => $newProject->organization_id,
                'workspace_id' => $newProject->owning_workspace_id,
                'project_id' => $newProject->id,
                'roadmap_id' => $defaultRoadmap->id,
                'roadmap_sort_order' => 1,
                'title' => '進めるための具体的な動き',
                'desired_state' => 'このRoadmapを具体的なTaskによって前へ進めます。',
                'status' => Improvement::STATUS_PLANNED,
                'visibility' => Improvement::VISIBILITY_INTERNAL,
                'proposed_by' => $request->user()->id,
                'assigned_to' => $request->user()->id,
            ]);

            ImprovementOutput::create([
                'improvement_id' => $improvement->id,
                'output_type' => ImprovementOutput::TYPE_PROJECT,
                'output_id' => $newProject->id,
                'created_by' => $request->user()->id,
            ]);

            return $newProject;
        });

        return redirect()->route('projects.show', $newProject)->with('status', 'Project output created from Improvement.');
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

    private function authorizeProjectImprovement(Project $project, Improvement $improvement): void
    {
        abort_unless($improvement->project_id === $project->id, 404);
    }
}
