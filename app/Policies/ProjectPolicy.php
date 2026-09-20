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
        if (! $user->canAccessWorkspace($workspace->id)) {
            return false;
        }

        $role = $user->workspaces()
            ->where('workspaces.id', $workspace->id)
            ->first()?->pivot?->role;

        return in_array($role, ['owner', 'admin', 'member'], true);
    }

    public function view(User $user, Project $project): bool
    {
        return $this->activeProjectMembership($user, $project) !== null;
    }

    public function update(User $user, Project $project): bool
    {
        $membership = $project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('permission_level', [
                ProjectMember::PERMISSION_ADMIN,
                ProjectMember::PERMISSION_EDIT,
            ])
            ->first();

        return $membership !== null && $user->canAccessWorkspace($membership->workspace_id);
    }

    public function manageMembers(User $user, Project $project, string $currentWorkspaceRole): bool
    {
        if (! in_array($currentWorkspaceRole, ['owner', 'admin', 'member'], true)) {
            return false;
        }

        $membership = $project->members()
            ->where('user_id', $user->id)
            ->where('permission_level', ProjectMember::PERMISSION_ADMIN)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first();

        return $membership !== null && $user->canAccessWorkspace($membership->workspace_id);
    }

    public function move(User $user, Project $project): bool
    {
        $membership = $project->members()
            ->where('user_id', $user->id)
            ->where('permission_level', ProjectMember::PERMISSION_ADMIN)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first();

        if (! $membership || ! $user->canAccessWorkspace($membership->workspace_id)) {
            return false;
        }

        $sourceRole = $user->workspaces()
            ->where('workspaces.id', $project->owning_workspace_id)
            ->first()?->pivot?->role;

        return in_array($sourceRole, ['owner', 'admin'], true);
    }

    public function delete(User $user, Project $project): bool
    {
        $membership = $project->members()
            ->where('user_id', $user->id)
            ->where('permission_level', ProjectMember::PERMISSION_ADMIN)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first();

        if (! $membership || ! $user->canAccessWorkspace($membership->workspace_id)) {
            return false;
        }

        $workspaceRole = $user->workspaces()
            ->where('workspaces.id', $project->owning_workspace_id)
            ->first()?->pivot?->role;

        return in_array($workspaceRole, ['owner', 'admin'], true);
    }

    private function activeProjectMembership(User $user, Project $project): ?ProjectMember
    {
        $membership = $project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first();

        return $membership && $user->canAccessWorkspace($membership->workspace_id)
            ? $membership
            : null;
    }
}
