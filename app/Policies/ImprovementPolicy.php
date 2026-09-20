<?php

namespace App\Policies;

use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;

class ImprovementPolicy
{
    public function create(User $user, Project $project): bool
    {
        return $this->activeProjectMembership($user, $project, [
            ProjectMember::PERMISSION_ADMIN,
            ProjectMember::PERMISSION_EDIT,
            ProjectMember::PERMISSION_COMMENT,
        ]) !== null;
    }

    public function view(User $user, Improvement $improvement): bool
    {
        $member = $this->activeProjectMembership($user, $improvement->project);

        if (! $member) {
            return false;
        }

        if ($member->project_role === ProjectMember::ROLE_CLIENT) {
            return $improvement->visibility === Improvement::VISIBILITY_CLIENT;
        }

        return true;
    }

    public function update(User $user, Improvement $improvement): bool
    {
        return $this->activeProjectMembership($user, $improvement->project, [
            ProjectMember::PERMISSION_ADMIN,
            ProjectMember::PERMISSION_EDIT,
            ProjectMember::PERMISSION_COMMENT,
        ]) !== null;
    }

    public function delete(User $user, Improvement $improvement): bool
    {
        return $this->update($user, $improvement);
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
