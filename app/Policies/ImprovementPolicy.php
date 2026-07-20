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

    public function view(User $user, Improvement $improvement): bool
    {
        $member = $improvement->project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->first();

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
        return $improvement->project->members()
            ->where('user_id', $user->id)
            ->where('status', ProjectMember::STATUS_ACTIVE)
            ->whereIn('permission_level', [
                ProjectMember::PERMISSION_ADMIN,
                ProjectMember::PERMISSION_EDIT,
                ProjectMember::PERMISSION_COMMENT,
            ])
            ->exists();
    }

    public function delete(User $user, Improvement $improvement): bool
    {
        return $this->update($user, $improvement);
    }
}
