<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\Project;
use App\Models\ProjectPlanVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProjectPlanSnapshotService
{
    public function capture(Project $project): array
    {
        $project->load([
            'roadmaps' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'roadmaps.improvements' => fn ($query) => $query->orderBy('roadmap_sort_order')->orderBy('id'),
            'roadmaps.improvements.tasks' => fn ($query) => $query->orderBy('id'),
        ]);

        return [
            'captured_at' => now()->toIso8601String(),
            'project' => $project->only([
                'public_id', 'name', 'summary', 'current_state', 'desired_future_state',
                'status', 'priority', 'start_date', 'due_date', 'duration_days',
            ]),
            'roadmaps' => $project->roadmaps->map(fn ($roadmap) => [
                ...$roadmap->only([
                    'public_id', 'title', 'purpose', 'status', 'sort_order',
                    'planned_start_date', 'target_date', 'planned_start_day', 'target_day',
                ]),
                'improvements' => $roadmap->improvements->map(fn ($improvement) => [
                    ...$improvement->only([
                        'public_id', 'title', 'current_state', 'desired_state', 'problem',
                        'hypothesis', 'action', 'result', 'impact', 'next_action',
                        'planned_effort_days', 'status', 'visibility',
                        'planned_start_date', 'target_date', 'planned_start_day', 'target_day',
                    ]),
                    'tasks' => $improvement->tasks->map(fn ($task) => $task->only([
                        'public_id', 'title', 'description', 'status', 'priority',
                        'planned_start_date', 'due_date', 'planned_start_day', 'due_day',
                        'completed_at', 'sort_order',
                    ]))->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'unclassified_improvements' => $project->improvements()
                ->whereNull('roadmap_id')
                ->orderBy('id')
                ->with(['tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
                ->get()
                ->map(fn ($improvement) => [
                    ...$improvement->only([
                        'public_id', 'title', 'current_state', 'desired_state', 'problem',
                        'hypothesis', 'action', 'result', 'impact', 'next_action',
                        'planned_effort_days', 'status', 'visibility',
                        'planned_start_date', 'target_date', 'planned_start_day', 'target_day',
                    ]),
                    'tasks' => $improvement->tasks->map(fn ($task) => $task->only([
                        'public_id', 'title', 'description', 'status', 'priority',
                        'planned_start_date', 'due_date', 'planned_start_day', 'due_day',
                        'completed_at', 'sort_order',
                    ]))->values()->all(),
                ])->values()->all(),
        ];
    }

    public function storeManualTimelineVersion(
        Project $project,
        User $creator,
        string $title,
        ?string $note = null,
    ): ProjectPlanVersion {
        return DB::transaction(function () use ($project, $creator, $title, $note): ProjectPlanVersion {
            $latest = $project->planVersions()->lockForUpdate()->first();
            $versionNumber = ((int) $project->planVersions()->lockForUpdate()->max('version_number')) + 1;
            $snapshot = $this->capture($project->fresh());

            return ProjectPlanVersion::create([
                'project_id' => $project->id,
                'version_number' => $versionNumber,
                'version_type' => ProjectPlanVersion::TYPE_TIMELINE,
                'title' => $title,
                'note' => $note,
                'change_summary' => $title,
                'previous_snapshot' => $latest?->plan_snapshot ?? [],
                'plan_snapshot' => $snapshot,
                'created_by' => $creator->id,
            ]);
        });
    }

    public function storeAppliedVersion(
        Project $project,
        AiProposal $proposal,
        User $reviewer,
        array $previousSnapshot,
    ): ProjectPlanVersion {
        $versionNumber = ((int) $project->planVersions()->lockForUpdate()->max('version_number')) + 1;

        return ProjectPlanVersion::create([
            'project_id' => $project->id,
            'version_number' => $versionNumber,
            'source_proposal_id' => $proposal->id,
            'version_type' => ProjectPlanVersion::TYPE_PROPOSAL,
            'title' => $proposal->title,
            'change_summary' => $proposal->summary ?: $proposal->title,
            'previous_snapshot' => $previousSnapshot,
            'plan_snapshot' => $this->capture($project->fresh()),
            'created_by' => $reviewer->id,
        ]);
    }
}
