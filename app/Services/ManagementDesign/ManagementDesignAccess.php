<?php

namespace App\Services\ManagementDesign;

use App\Models\ManagementDesignAccessSetting;
use App\Models\ManagementDesignGrant;
use App\Models\ManagementDesignItem;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

class ManagementDesignAccess
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

    public function authorizeMembership(User $user, Organization $organization, bool $lockForUpdate = false): OrganizationUser
    {
        $membership = $this->membership($user, $organization, $lockForUpdate);
        if (! $this->isActive($user, $membership)) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeView(
        User $user,
        Organization $organization,
        string $type,
        bool $lockForUpdate = false,
    ): OrganizationUser {
        $this->assertType($type);
        $membership = $this->authorizeMembership($user, $organization, $lockForUpdate);
        $scopeQuery = ManagementDesignAccessSetting::query()
            ->where('organization_id', $organization->id)
            ->where('item_type', $type);
        if ($lockForUpdate) {
            $scopeQuery->lockForUpdate();
        }
        $scope = $scopeQuery->value('view_scope') ?? ManagementDesignAccessSetting::VIEW_SCOPE_EXPLICIT;
        if ($scope === ManagementDesignAccessSetting::VIEW_SCOPE_ALL_ACTIVE_STAFF) {
            return $membership;
        }

        $grantQuery = $this->grantQuery($organization, $membership, $type);
        if ($lockForUpdate) {
            $grantQuery->lockForUpdate();
        }
        if (! (bool) $grantQuery->value('can_view')) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeEdit(
        User $user,
        Organization $organization,
        string $type,
        bool $lockForUpdate = false,
    ): OrganizationUser {
        $membership = $this->authorizeView($user, $organization, $type, $lockForUpdate);
        $grantQuery = $this->grantQuery($organization, $membership, $type);
        if ($lockForUpdate) {
            $grantQuery->lockForUpdate();
        }
        if (! (bool) $grantQuery->value('can_edit')) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeManage(User $user, Organization $organization, bool $lockForUpdate = false): OrganizationUser
    {
        $membership = $this->authorizeMembership($user, $organization, $lockForUpdate);
        if ($membership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function canView(User $user, Organization $organization, string $type): bool
    {
        return $this->allows(fn () => $this->authorizeView($user, $organization, $type));
    }

    public function canEdit(User $user, Organization $organization, string $type): bool
    {
        return $this->allows(fn () => $this->authorizeEdit($user, $organization, $type));
    }

    public function canManage(User $user, Organization $organization): bool
    {
        return $this->allows(fn () => $this->authorizeManage($user, $organization));
    }

    private function grantQuery(Organization $organization, OrganizationUser $membership, string $type)
    {
        return ManagementDesignGrant::query()
            ->where('organization_id', $organization->id)
            ->where('organization_user_id', $membership->id)
            ->where('item_type', $type);
    }

    private function isActive(User $user, ?OrganizationUser $membership): bool
    {
        return $user->is_active
            && $membership !== null
            && $membership->membership_status === OrganizationUser::STATUS_ACTIVE;
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, ManagementDesignItem::TYPES, true)) {
            throw new InvalidArgumentException('Unknown Management Design type.');
        }
    }

    private function allows(callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }
}
