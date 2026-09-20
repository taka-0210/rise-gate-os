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
        return $this->activeProjectMembership($user, $project, [
            ProjectMember::PERMISSION_ADMIN,
            ProjectMember::PERMISSION_EDIT,
            ProjectMember::PERMISSION_COMMENT,
        ]) !== null;
    }

    public function view(User $user, Task $task): bool
    {
        return $this->activeProjectMembership($user, $task->project) !== null;
    }

    public function update(User $user, Task $task): bool
    {
        return $this->activeProjectMembership($user, $task->project, [
            ProjectMember::PERMISSION_ADMIN,
            ProjectMember::PERMISSION_EDIT,
            ProjectMember::PERMISSION_COMMENT,
        ]) !== null;
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    private function activeProjectMembership(
        User $user,
        Project $project,
        ?array $permissionLevels = null,
    ): ?ProjectMember {
        $query = $project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE);

        if ($permissionLevels !== null) {
            $query->whereIn('permission_level', $permissionLevels);
        }

        $membership = $query->first();

        return $membership && $user->canAccessWorkspace($membership->workspace_id)
            ? $membership
            : null;
    }
}
