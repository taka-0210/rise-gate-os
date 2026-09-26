<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class AiCommonAccess
{
    public function authorizeOrganization(User $user, Organization $organization, bool $lock = false): OrganizationUser
    {
        $query = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE);
        if ($lock) {
            $query->lockForUpdate();
        }
        $membership = $query->first();
        $eligibility = ProductAccountEligibility::query()->where('user_id', $user->id)->first();
        if (! $user->is_active || ! $membership || ! $eligibility
            || ($eligibility->mode === ProductAccountEligibility::MODE_SINGLE
                && $eligibility->product_organization_id !== $organization->id)) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function authorizeConversation(User $user, Organization $organization, AiCommonConversation $conversation): void
    {
        $this->authorizeOrganization($user, $organization);
        if ($conversation->organization_id !== $organization->id || $conversation->user_id !== $user->id) {
            throw new AuthorizationException;
        }
    }

    public function authorizePolicyManager(User $user, Organization $organization): OrganizationUser
    {
        $membership = $this->authorizeOrganization($user, $organization);
        if ($membership->organization_role !== OrganizationUser::ORGANIZATION_ROLE_OWNER) {
            throw new AuthorizationException;
        }

        return $membership;
    }

    public function policy(Organization $organization): ?OrganizationAiPolicy
    {
        return OrganizationAiPolicy::query()->where('organization_id', $organization->id)->first();
    }

    public function authorizeCategory(User $user, Organization $organization, string $category): OrganizationAiPolicy
    {
        $this->authorizeOrganization($user, $organization);
        $policy = $this->policy($organization);
        if (! $policy?->allows($category)) {
            throw new AuthorizationException;
        }

        return $policy;
    }
}
