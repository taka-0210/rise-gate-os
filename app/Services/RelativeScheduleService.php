<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class RelativeScheduleService
{
    public function anchor(Project $project): void
    {
        if (! $project->start_date) {
            return;
        }

        DB::transaction(function () use ($project): void {
            foreach ($project->roadmaps()->with('improvements.tasks')->get() as $roadmap) {
                if ($roadmap->planned_start_day && $roadmap->target_day) {
                    $roadmap->update([
                        'planned_start_date' => $project->start_date->copy()->addDays($roadmap->planned_start_day - 1),
                        'target_date' => $project->start_date->copy()->addDays($roadmap->target_day - 1),
                    ]);
                }

                foreach ($roadmap->improvements as $improvement) {
                    if ($improvement->planned_start_day && $improvement->target_day) {
                        $improvement->update([
                            'planned_start_date' => $project->start_date->copy()->addDays($improvement->planned_start_day - 1),
                            'target_date' => $project->start_date->copy()->addDays($improvement->target_day - 1),
                        ]);
                    }

                    foreach ($improvement->tasks as $task) {
                        if ($task->planned_start_day && $task->due_day) {
                            $task->update([
                                'planned_start_date' => $project->start_date->copy()->addDays($task->planned_start_day - 1),
                                'due_date' => $project->start_date->copy()->addDays($task->due_day - 1),
                            ]);
                        }
                    }
                }
            }
        });
    }
}
