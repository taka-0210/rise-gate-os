<?php

namespace App\Services\BusinessDomain;

use App\Models\BusinessDomainEditorGrant;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class BusinessDomainAccess
{
    public function membership(User $user, Organization $organization, bool $lockForUpdate = false): ?OrganizationUser
    {
        $query = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function authorizeView(User $user, Organization $organization, bool $lockForUpdate = false): OrganizationUser
    {
        $membership = $this->membership($user, $organization, $lockForUpdate);
        if (! $this->isActive($user, $membership)) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function canEdit(User $user, Organization $organization): bool
    {
        try {
            $this->authorizeEdit($user, $organization);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function authorizeEdit(User $user, Organization $organization, bool $lockForUpdate = false): OrganizationUser
    {
        $membership = $this->authorizeView($user, $organization, $lockForUpdate);

        if ($membership->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            return $membership;
        }

        if (! in_array($membership->organization_role, [
            OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
        ], true)) {
            throw new AuthorizationException;
        }

        $grantQuery = BusinessDomainEditorGrant::query()
            ->where('organization_id', $organization->id)
            ->where('organization_user_id', $membership->id);
        if ($lockForUpdate) {
            $grantQuery->lockForUpdate();
        }
        $grant = $grantQuery->first();
        if (! $grant || $grant->revoked_at !== null) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeHistory(User $user, Organization $organization): OrganizationUser
    {
        return $this->authorizeEdit($user, $organization);
    }

    public function authorizeOwner(User $user, Organization $organization, bool $lockForUpdate = false): OrganizationUser
    {
        $membership = $this->authorizeView($user, $organization, $lockForUpdate);
        if ($membership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    private function isActive(User $user, ?OrganizationUser $membership): bool
    {
        return $user->is_active
            && $membership !== null
            && $membership->membership_status === OrganizationUser::STATUS_ACTIVE;
    }
}
