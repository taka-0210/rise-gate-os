<?php

namespace App\Services;

use App\Models\OrganizationUser;
use App\Models\User;

class UserAvatarAccess
{
    public function allows(User $viewer, User $subject): bool
    {
        if ($viewer->id === $subject->id || $viewer->is_system_admin) {
            return true;
        }

        $viewerOrganizationIds = OrganizationUser::query()
            ->where('user_id', $viewer->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->pluck('organization_id');

        return $viewerOrganizationIds->isNotEmpty()
            && OrganizationUser::query()
                ->where('user_id', $subject->id)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->whereIn('organization_id', $viewerOrganizationIds)
                ->exists();
    }
}
