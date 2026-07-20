<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class RelativeScheduleService
{
    public function capture(Project $project): void
    {
        $project->loadMissing(['roadmaps.improvements.tasks']);
        $origin = collect()
            ->concat($project->roadmaps->pluck('planned_start_date'))
            ->concat($project->improvements->pluck('planned_start_date'))
            ->concat($project->tasks->pluck('planned_start_date'))
            ->filter()->min();
        if (! $origin) return;

        $day = fn ($date) => $date ? $origin->diffInDays($date) + 1 : null;
        foreach ($project->roadmaps as $roadmap) {
            $roadmap->update(['planned_start_day' => $roadmap->planned_start_day ?: $day($roadmap->planned_start_date), 'target_day' => $roadmap->target_day ?: $day($roadmap->target_date)]);
            foreach ($roadmap->improvements as $improvement) {
                $improvement->update(['planned_start_day' => $improvement->planned_start_day ?: $day($improvement->planned_start_date), 'target_day' => $improvement->target_day ?: $day($improvement->target_date)]);
                foreach ($improvement->tasks as $task) {
                    $task->update(['planned_start_day' => $task->planned_start_day ?: $day($task->planned_start_date), 'due_day' => $task->due_day ?: $day($task->due_date)]);
                }
            }
        }
    }

    public function anchor(Project $project): void
    {
        if (! $project->start_date) {
            return;
        }

        DB::transaction(function () use ($project): void {
            $origin = collect()
                ->concat($project->roadmaps()->get()->pluck('planned_start_date'))
                ->concat($project->improvements()->get()->pluck('planned_start_date'))
                ->concat($project->tasks()->get()->pluck('planned_start_date'))
                ->filter()->min();
            $day = fn ($relative, $date) => $relative ?: ($origin && $date ? $origin->diffInDays($date) + 1 : null);
            foreach ($project->roadmaps()->with('improvements.tasks')->get() as $roadmap) {
                $roadmapStartDay = $day($roadmap->planned_start_day, $roadmap->planned_start_date);
                $roadmapTargetDay = $day($roadmap->target_day, $roadmap->target_date);
                if ($roadmapStartDay && $roadmapTargetDay) {
                    $roadmap->update([
                        'planned_start_day' => $roadmapStartDay,
                        'target_day' => $roadmapTargetDay,
                        'planned_start_date' => $project->start_date->copy()->addDays($roadmapStartDay - 1),
                        'target_date' => $project->start_date->copy()->addDays($roadmapTargetDay - 1),
                    ]);
                }

                foreach ($roadmap->improvements as $improvement) {
                    $improvementStartDay = $day($improvement->planned_start_day, $improvement->planned_start_date);
                    $improvementTargetDay = $day($improvement->target_day, $improvement->target_date);
                    if ($improvementStartDay && $improvementTargetDay) {
                        $improvement->update([
                            'planned_start_day' => $improvementStartDay,
                            'target_day' => $improvementTargetDay,
                            'planned_start_date' => $project->start_date->copy()->addDays($improvementStartDay - 1),
                            'target_date' => $project->start_date->copy()->addDays($improvementTargetDay - 1),
                        ]);
                    }

                    foreach ($improvement->tasks as $task) {
                        $taskStartDay = $day($task->planned_start_day, $task->planned_start_date);
                        $taskDueDay = $day($task->due_day, $task->due_date);
                        if ($taskStartDay && $taskDueDay) {
                            $task->update([
                                'planned_start_day' => $taskStartDay,
                                'due_day' => $taskDueDay,
                                'planned_start_date' => $project->start_date->copy()->addDays($taskStartDay - 1),
                                'due_date' => $project->start_date->copy()->addDays($taskDueDay - 1),
                            ]);
                        }
                    }
                }
            }
        });
    }
}
