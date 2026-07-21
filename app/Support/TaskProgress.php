<?php

namespace App\Support;

use App\Models\Task;
use Illuminate\Support\Collection;

class TaskProgress
{
    public static function calculate(Collection $tasks): array
    {
        $activeTasks = $tasks->where('status', '!=', Task::STATUS_ARCHIVED)->values();
        $total = $activeTasks->count();
        $completed = $activeTasks->where('status', Task::STATUS_DONE)->count();
        $percentage = $total === 0 ? 0 : (int) round(($completed / $total) * 100);

        $key = match (true) {
            $total === 0 => 'not_started',
            $completed === $total => 'completed',
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
            'completed' => $completed,
            'total' => $total,
        ];
    }
}
