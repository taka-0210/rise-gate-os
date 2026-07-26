<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\Project;
use App\Models\ProjectPlanVersion;
use App\Models\Task;
use App\Models\User;
use App\Support\EffortProgress;
use Illuminate\Support\Facades\DB;

class ProjectPlanSnapshotService
{
    public function capture(Project $project): array
    {
        $relative = ! $project->start_date && (int) $project->duration_days > 0;

        $project->load([
            'roadmaps' => function ($query) use ($relative): void {
                $query->reorder()
                    ->orderByRaw($relative
                        ? 'CASE WHEN planned_start_day IS NULL THEN 1 ELSE 0 END'
                        : 'CASE WHEN planned_start_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy($relative ? 'planned_start_day' : 'planned_start_date')
                    ->orderBy($relative ? 'target_day' : 'target_date')
                    ->orderBy('sort_order')
                    ->orderBy('id');
            },
            'roadmaps.improvements' => function ($query) use ($relative): void {
                $query->reorder()
                    ->orderByRaw($relative
                        ? 'CASE WHEN planned_start_day IS NULL THEN 1 ELSE 0 END'
                        : 'CASE WHEN planned_start_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy($relative ? 'planned_start_day' : 'planned_start_date')
                    ->orderBy($relative ? 'target_day' : 'target_date')
                    ->orderBy('roadmap_sort_order')
                    ->orderBy('id');
            },
            'roadmaps.improvements.tasks' => function ($query) use ($relative): void {
                $query->reorder()
                    ->orderByRaw($relative
                        ? 'CASE WHEN planned_start_day IS NULL THEN 1 ELSE 0 END'
                        : 'CASE WHEN planned_start_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy($relative ? 'planned_start_day' : 'planned_start_date')
                    ->orderBy($relative ? 'due_day' : 'due_date')
                    ->orderBy('id');
            },
        ]);

        $projectData = $project->only([
            'public_id', 'name', 'summary', 'current_state', 'desired_future_state',
            'status', 'priority', 'start_date', 'due_date', 'duration_days',
        ]);
        $projectData['start_date'] = $this->dateString($project->start_date);
        $projectData['due_date'] = $this->dateString($project->due_date);

        $unclassifiedImprovements = $project->improvements()
            ->whereNull('roadmap_id')
            ->orderBy('id')
            ->with(['tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')])
            ->get();
        $improvements = $project->roadmaps->flatMap->improvements
            ->concat($unclassifiedImprovements)
            ->values();
        $tasks = $improvements->flatMap->tasks
            ->where('status', '!=', Task::STATUS_ARCHIVED)
            ->values();
        $progress = EffortProgress::calculate($improvements);
        $actualEffortMinutes = (int) $project->actuals()->sum('effort_minutes');

        return [
            'captured_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
            'project' => $projectData,
            'metrics' => [
                'calculation_version' => 1,
                'planned_effort_days' => (float) $progress['total'],
                'earned_effort_days' => (float) $progress['completed'],
                'progress_percentage' => (int) $progress['percentage'],
                'completed_tasks' => $tasks->where('status', Task::STATUS_DONE)->count(),
                'total_tasks' => $tasks->count(),
                'unset_effort_count' => (int) $progress['unset_effort_count'],
                'actual_effort_minutes' => $actualEffortMinutes,
                'actual_effort_hours' => round($actualEffortMinutes / 60, 2),
            ],
            'roadmaps' => $project->roadmaps->map(fn ($roadmap) => [
                ...$roadmap->only([
                    'public_id', 'title', 'purpose', 'status', 'sort_order',
                    'planned_start_date', 'target_date', 'planned_start_day', 'target_day',
                ]),
                'planned_start_date' => $this->dateString($roadmap->planned_start_date),
                'target_date' => $this->dateString($roadmap->target_date),
                'improvements' => $roadmap->improvements->map(fn ($improvement) => [
                    ...$improvement->only([
                        'public_id', 'title', 'current_state', 'desired_state', 'problem',
                        'hypothesis', 'action', 'result', 'impact', 'next_action',
                        'planned_effort_days', 'status', 'visibility', 'roadmap_sort_order',
                        'planned_start_date', 'target_date', 'planned_start_day', 'target_day',
                    ]),
                    'planned_start_date' => $this->dateString($improvement->planned_start_date),
                    'target_date' => $this->dateString($improvement->target_date),
                    'tasks' => $improvement->tasks->map(fn ($task) => array_replace($task->only([
                        'public_id', 'title', 'description', 'status', 'priority',
                        'planned_start_date', 'due_date', 'planned_start_day', 'due_day',
                        'completed_at', 'sort_order',
                    ]), [
                        'planned_start_date' => $this->dateString($task->planned_start_date),
                        'due_date' => $this->dateString($task->due_date),
                    ]))->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'unclassified_improvements' => $unclassifiedImprovements->map(fn ($improvement) => [
                ...$improvement->only([
                    'public_id', 'title', 'current_state', 'desired_state', 'problem',
                    'hypothesis', 'action', 'result', 'impact', 'next_action',
                    'planned_effort_days', 'status', 'visibility', 'roadmap_sort_order',
                    'planned_start_date', 'target_date', 'planned_start_day', 'target_day',
                ]),
                'planned_start_date' => $this->dateString($improvement->planned_start_date),
                'target_date' => $this->dateString($improvement->target_date),
                'tasks' => $improvement->tasks->map(fn ($task) => array_replace($task->only([
                    'public_id', 'title', 'description', 'status', 'priority',
                    'planned_start_date', 'due_date', 'planned_start_day', 'due_day',
                    'completed_at', 'sort_order',
                ]), [
                    'planned_start_date' => $this->dateString($task->planned_start_date),
                    'due_date' => $this->dateString($task->due_date),
                ]))->values()->all(),
            ])->values()->all(),
        ];
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;

        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');

        return substr((string) $value, 0, 10);
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
