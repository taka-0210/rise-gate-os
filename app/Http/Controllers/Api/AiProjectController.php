<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAccessKey;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $key = $this->key($request);
        $projects = $this->visibleProjects($key)
            ->withCount(['roadmaps', 'improvements', 'tasks'])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'public_id' => $project->public_id,
                'name' => $project->name,
                'summary' => $project->summary,
                'status' => $project->status,
                'priority' => $project->priority,
                'roadmaps_count' => $project->roadmaps_count,
                'improvements_count' => $project->improvements_count,
                'tasks_count' => $project->tasks_count,
            ]);

        return response()->json([
            'workspace' => ['public_id' => $key->workspace->public_id, 'name' => $key->workspace->name],
            'member' => $key->user ? ['id' => $key->user->id, 'name' => $key->user->name] : null,
            'projects' => $projects,
        ]);
    }

    public function show(Request $request, string $projectPublicId): JsonResponse
    {
        $key = $this->key($request);
        $project = $this->visibleProjects($key)
            ->where('public_id', $projectPublicId)
            ->with(['roadmaps.improvements.tasks'])
            ->firstOrFail();

        return response()->json([
            'project' => [
                'public_id' => $project->public_id,
                'name' => $project->name,
                'summary' => $project->summary,
                'status' => $project->status,
                'priority' => $project->priority,
                'roadmaps' => $project->roadmaps->map(fn ($roadmap) => [
                    'public_id' => $roadmap->public_id,
                    'title' => $roadmap->title,
                    'purpose' => $roadmap->purpose,
                    'status' => $roadmap->status,
                    'improvements' => $roadmap->improvements->map(fn ($improvement) => [
                        'public_id' => $improvement->public_id,
                        'title' => $improvement->title,
                        'status' => $improvement->status,
                        'next_action' => $improvement->next_action,
                        'tasks' => $improvement->tasks->map(fn ($task) => [
                            'public_id' => $task->public_id,
                            'title' => $task->title,
                            'description' => $task->description,
                            'status' => $task->status,
                            'priority' => $task->priority,
                            'due_date' => $task->due_date?->toDateString(),
                            'assigned_to' => $task->assigned_to,
                        ])->values(),
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    private function key(Request $request): AiAccessKey
    {
        return $request->attributes->get('aiAccessKey')->loadMissing(['workspace', 'user']);
    }

    private function visibleProjects(AiAccessKey $key)
    {
        return Project::query()
            ->where('owning_workspace_id', $key->workspace_id)
            ->when($key->user_id, fn ($query) => $query->whereHas('members', fn ($members) => $members
                ->where('user_id', $key->user_id)
                ->where('status', ProjectMember::STATUS_ACTIVE)));
    }
}
