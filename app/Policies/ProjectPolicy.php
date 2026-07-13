<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;

class ProjectPolicy
{
    public function create(User $user, Workspace $workspace): bool
    {
        $role = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first()?->pivot?->role;

        return in_array($role, ['owner', 'admin', 'member'], true);
    }

    public function view(User $user, Project $project): bool
    {
        return $project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->exists();
    }

    public function update(User $user, Project $project): bool
    {
        return $project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('permission_level', [
                ProjectMember::PERMISSION_ADMIN,
                ProjectMember::PERMISSION_EDIT,
            ])
            ->exists();
    }

    public function manageMembers(User $user, Project $project, string $currentWorkspaceRole): bool
    {
        if (! in_array($currentWorkspaceRole, ['owner', 'admin', 'member'], true)) {
            return false;
        }

        return $project->members()
            ->where('user_id', $user->id)
            ->where('permission_level', ProjectMember::PERMISSION_ADMIN)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->exists();
    }
}
