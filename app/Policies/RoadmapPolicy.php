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
        return $project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('permission_level', [
                ProjectMember::PERMISSION_ADMIN,
                ProjectMember::PERMISSION_EDIT,
            ])
            ->exists();
    }

    public function view(User $user, Roadmap $roadmap): bool
    {
        return $roadmap->project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->exists();
    }

    public function update(User $user, Roadmap $roadmap): bool
    {
        return $roadmap->project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('permission_level', [
                ProjectMember::PERMISSION_ADMIN,
                ProjectMember::PERMISSION_EDIT,
            ])
            ->exists();
    }
}
