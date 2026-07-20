<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function create(User $user, Project $project): bool
    {
        return $project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('permission_level', [
                ProjectMember::PERMISSION_ADMIN,
                ProjectMember::PERMISSION_EDIT,
                ProjectMember::PERMISSION_COMMENT,
            ])
            ->exists();
    }

    public function view(User $user, Task $task): bool
    {
        return $task->project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->exists();
    }

    public function update(User $user, Task $task): bool
    {
        return $task->project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('permission_level', [
                ProjectMember::PERMISSION_ADMIN,
                ProjectMember::PERMISSION_EDIT,
                ProjectMember::PERMISSION_COMMENT,
            ])
            ->exists();
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
