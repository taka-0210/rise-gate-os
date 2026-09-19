<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class OrganizationAccess
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

    public function hasActiveMembership(User $user, Organization $organization): bool
    {
        return $user->is_active && OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->exists();
    }

    public function canManage(User $user, Organization $organization): bool
    {
        $membership = $this->membership($user, $organization);

        return $this->isActive($user, $membership)
            && in_array($membership->organization_role, [
                OrganizationUser::ORGANIZATION_ROLE_OWNER,
                OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            ], true);
    }

    public function canChangeRoles(User $user, Organization $organization): bool
    {
        $membership = $this->membership($user, $organization);

        return $this->isActive($user, $membership)
            && $membership->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER;
    }

    public function authorizeManage(User $user, Organization $organization): OrganizationUser
    {
        $membership = $this->membership($user, $organization);
        if (! $this->isActive($user, $membership)
            || ! in_array($membership->organization_role, [
                OrganizationUser::ORGANIZATION_ROLE_OWNER,
                OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            ], true)) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeManageLocked(User $user, Organization $organization): OrganizationUser
    {
        $membership = $this->membership($user, $organization, true);
        if (! $this->isActive($user, $membership)
            || ! in_array($membership->organization_role, [
                OrganizationUser::ORGANIZATION_ROLE_OWNER,
                OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            ], true)) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeRoleChangeLocked(User $user, Organization $organization): OrganizationUser
    {
        $membership = $this->membership($user, $organization, true);
        if (! $this->isActive($user, $membership)
            || $membership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function activeMembershipQuery(Organization $organization): Builder
    {
        return OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->whereHas('user', fn (Builder $query) => $query->where('is_active', true));
    }

    private function isActive(User $user, ?OrganizationUser $membership): bool
    {
        return $user->is_active
            && $membership
            && $membership->membership_status === OrganizationUser::STATUS_ACTIVE;
    }
}
