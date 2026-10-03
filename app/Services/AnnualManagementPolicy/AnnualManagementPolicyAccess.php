<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\AnnualManagementPolicy;
use App\Models\AnnualManagementPolicyGrant;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AnnualManagementPolicyAccess
{
    public function authorizeMembership(User $user, Organization $organization, bool $lock = false): OrganizationUser
    {
        $query = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $membership = $query->first();
        if (! $user->is_active || ! $membership || $membership->membership_status !== OrganizationUser::STATUS_ACTIVE) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeManage(User $user, Organization $organization, bool $lock = false): OrganizationUser
    {
        $membership = $this->authorizeMembership($user, $organization, $lock);
        if ($membership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeApprovedView(User $user, AnnualManagementPolicy $policy, bool $lock = false): OrganizationUser
    {
        $membership = $this->authorizeMembership($user, $policy->organization, $lock);
        if ($policy->approved_view_scope === AnnualManagementPolicy::VIEW_SCOPE_ALL_ACTIVE_STAFF) {
            return $membership;
        }
        $grant = $this->grant($policy, $membership, $lock);
        if (! $grant?->can_view_approved) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeDraftView(User $user, AnnualManagementPolicy $policy, bool $lock = false): OrganizationUser
    {
        $membership = $this->authorizeMembership($user, $policy->organization, $lock);
        if (! $this->grant($policy, $membership, $lock)?->can_view_draft) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeEdit(User $user, AnnualManagementPolicy $policy, bool $lock = false): OrganizationUser
    {
        $membership = $this->authorizeDraftView($user, $policy, $lock);
        $grant = $this->grant($policy, $membership, $lock);
        if (! $grant?->can_edit) {
            throw new AuthorizationException;
        }
        if ($policy->current_approved_revision_id !== null) {
            $this->authorizeApprovedView($user, $policy, $lock);
        }

        return $membership;
    }

    public function authorizeApprove(User $user, AnnualManagementPolicy $policy, bool $lock = false): OrganizationUser
    {
        $membership = $this->authorizeDraftView($user, $policy, $lock);
        $grant = $this->grant($policy, $membership, $lock);
        if (! $grant?->can_approve) {
            throw new AuthorizationException;
        }
        if ($policy->current_approved_revision_id !== null) {
            $this->authorizeApprovedView($user, $policy, $lock);
        }

        return $membership;
    }

    public function canManage(User $user, Organization $organization): bool { return $this->allows(fn () => $this->authorizeManage($user, $organization)); }
    public function canViewApproved(User $user, AnnualManagementPolicy $policy): bool { return $this->allows(fn () => $this->authorizeApprovedView($user, $policy)); }
    public function canViewDraft(User $user, AnnualManagementPolicy $policy): bool { return $this->allows(fn () => $this->authorizeDraftView($user, $policy)); }
    public function canEdit(User $user, AnnualManagementPolicy $policy): bool { return $this->allows(fn () => $this->authorizeEdit($user, $policy)); }
    public function canApprove(User $user, AnnualManagementPolicy $policy): bool { return $this->allows(fn () => $this->authorizeApprove($user, $policy)); }

    private function grant(AnnualManagementPolicy $policy, OrganizationUser $membership, bool $lock): ?AnnualManagementPolicyGrant
    {
        $query = AnnualManagementPolicyGrant::query()
            ->where('annual_management_policy_id', $policy->id)
            ->where('organization_user_id', $membership->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
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
