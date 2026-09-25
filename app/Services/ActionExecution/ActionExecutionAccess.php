<?php

namespace App\Services\ActionExecution;

use App\Models\ActionExecution;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectExecution\ProjectExecutionAccess;

class ActionExecutionAccess
{
    public function __construct(private readonly ProjectExecutionAccess $projects) {}
    public function canRead(User $user, Task $task): bool { return $this->projects->activeExplicitMember($user, $task->project) !== null; }
    public function canConfigure(User $user, Task $task): bool { return $this->projects->canEditAction($user, $task); }
    public function canComplete(User $user, ActionExecution $execution): bool { return $execution->task->assigned_to === $user->id && $this->projects->canExecuteAction($user, $execution->task); }
    public function canSkip(User $user, ActionExecution $execution): bool { return $this->canComplete($user, $execution) || $this->projects->canManageStructure($user, $execution->task->project); }
}
