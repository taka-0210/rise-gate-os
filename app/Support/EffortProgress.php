<?php

namespace App\Support;

use App\Models\Improvement;
use App\Models\Task;
use Illuminate\Support\Collection;

class EffortProgress
{
    public static function calculate(Collection $improvements): array
    {
        $plannedEffort = 0.0;
        $earnedEffort = 0.0;
        $activeTasks = collect();
        $unsetEffortCount = 0;

        foreach ($improvements as $improvement) {
            $tasks = $improvement->relationLoaded('tasks')
                ? $improvement->tasks
                : $improvement->tasks()->get();
            $tasks = $tasks->where('status', '!=', Task::STATUS_ARCHIVED)->values();
            $activeTasks = $activeTasks->concat($tasks);

            if ($improvement->planned_effort_days === null) {
                $unsetEffortCount++;
                continue;
            }

            $effort = (float) $improvement->planned_effort_days;
            $plannedEffort += $effort;

            if ($tasks->isNotEmpty()) {
                $earnedEffort += $effort * ($tasks->where('status', Task::STATUS_DONE)->count() / $tasks->count());
            }
        }

        $percentage = $plannedEffort <= 0
            ? 0
            : (int) round(($earnedEffort / $plannedEffort) * 100);
        $allTasksCompleted = $activeTasks->isNotEmpty()
            && $activeTasks->every(fn (Task $task) => $task->status === Task::STATUS_DONE);

        $key = match (true) {
            $plannedEffort <= 0 || $activeTasks->isEmpty() => 'not_started',
            $allTasksCompleted => 'completed',
            $activeTasks->contains(fn (Task $task) => in_array($task->status, [Task::STATUS_IN_PROGRESS, Task::STATUS_DONE], true)) => 'in_progress',
            default => 'not_started',
        };

        return [
            'key' => $key,
            'label' => match ($key) {
                'completed' => '完了',
                'in_progress' => '進行中',
                default => '未着手',
            },
            'percentage' => $percentage,
            'completed' => $earnedEffort,
            'total' => $plannedEffort,
            'unset_effort_count' => $unsetEffortCount,
            'unit' => '人日',
        ];
    }
}
