<?php

namespace App\Services\Company;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;

class CompanyAccess
{
    public function allows(User $user, Organization $organization, string $permission): bool
    {
        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership) {
            return false;
        }

        if ($membership->role === OrganizationUser::ROLE_OWNER) {
            return true;
        }

        if ($membership->role === OrganizationUser::ROLE_ADMIN
            && in_array($permission, OrganizationUser::adminPermissions(), true)) {
            return true;
        }

        return in_array($permission, $membership->permissions ?? [], true);
    }

    public function canManageMembers(User $user, Organization $organization): bool
    {
        return $this->allows($user, $organization, OrganizationUser::PERMISSION_MEMBERS_MANAGE);
    }
}
