<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\User;

class RoadmapPolicy
{
    public function create(User $user, Project $project): bool
    {
        return $this->activeProjectMembership($user, $project, [
            ProjectMember::PERMISSION_ADMIN,
            ProjectMember::PERMISSION_EDIT,
        ]) !== null;
    }

    public function view(User $user, Roadmap $roadmap): bool
    {
        return $this->activeProjectMembership($user, $roadmap->project) !== null;
    }

    public function update(User $user, Roadmap $roadmap): bool
    {
        return $this->activeProjectMembership($user, $roadmap->project, [
            ProjectMember::PERMISSION_ADMIN,
            ProjectMember::PERMISSION_EDIT,
        ]) !== null;
    }

    public function delete(User $user, Roadmap $roadmap): bool
    {
        return $this->update($user, $roadmap);
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
