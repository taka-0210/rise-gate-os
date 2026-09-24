<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectExecution\ProjectExecutionAccess;

class TaskPolicy
{
    public function __construct(private readonly ProjectExecutionAccess $executionAccess) {}

    public function create(User $user, Project $project): bool
    {
        if ($project->usesScopeEight()) {
            return $this->executionAccess->canCreateAction($user, $project);
        }
        return $this->activeProjectMembership($user, $project, [
            ProjectMember::PERMISSION_ADMIN,
            ProjectMember::PERMISSION_EDIT,
            ProjectMember::PERMISSION_COMMENT,
        ]) !== null;
    }

    public function view(User $user, Task $task): bool
    {
        if ($task->project->usesScopeEight()) {
            return $this->executionAccess->canRead($user, $task->project);
        }
        return $this->activeProjectMembership($user, $task->project) !== null;
    }

    public function update(User $user, Task $task): bool
    {
        if ($task->project->usesScopeEight()) {
            return $this->executionAccess->canEditAction($user, $task);
        }
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
