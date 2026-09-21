<?php

namespace App\Services\ProductOrganization;

use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use Illuminate\Support\Collection;

class ProductOrganizationResolver
{
    public function enabled(): bool
    {
        return (bool) config('product_ux.organization_admission_enabled');
    }

    public function resolve(User $user): array
    {
        if (! $this->enabled()) {
            $organizations = $this->activeMembershipOrganizations($user);

            return [
                'mode' => 'legacy_passthrough',
                'state' => $organizations->isEmpty() ? 'unstarted' : ($organizations->count() === 1 ? 'ready' : 'selection'),
                'organizations' => $organizations,
                'organization' => $organizations->count() === 1 ? $organizations->first() : null,
                'membership' => null,
            ];
        }

        $eligibility = ProductAccountEligibility::query()
            ->where('user_id', $user->id)
            ->first();
        if (! $eligibility) {
            return $this->reviewState($user);
        }
        if ($eligibility->mode === ProductAccountEligibility::MODE_UNSTARTED) {
            return $this->state('unstarted', $eligibility);
        }
        if ($eligibility->mode === ProductAccountEligibility::MODE_REVIEW_REQUIRED) {
            return $this->reviewState($user, $eligibility);
        }
        if ($eligibility->mode === ProductAccountEligibility::MODE_SINGLE) {
            $membership = OrganizationUser::query()
                ->with('organization')
                ->where('user_id', $user->id)
                ->where('organization_id', $eligibility->product_organization_id)
                ->first();
            if (! $membership) {
                return $this->state('review_required', $eligibility);
            }
            $state = match ($membership->membership_status) {
                OrganizationUser::STATUS_ACTIVE => 'ready',
                OrganizationUser::STATUS_SUSPENDED => 'suspended',
                OrganizationUser::STATUS_LEFT => 'left',
                default => 'unstarted',
            };

            return [
                'mode' => $eligibility->mode,
                'state' => $state,
                'organizations' => $state === 'ready' ? collect([$membership->organization]) : collect(),
                'organization' => $state === 'ready' ? $membership->organization : null,
                'membership' => $membership,
                'eligibility' => $eligibility,
            ];
        }

        $memberships = OrganizationUser::query()
            ->with('organization')
            ->where('user_id', $user->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->whereIn('id', $eligibility->compatibilities()->select('organization_user_id'))
            ->orderBy('organization_id')
            ->get();

        return [
            'mode' => $eligibility->mode,
            'state' => $memberships->isEmpty() ? 'review_required' : 'selection',
            'organizations' => $memberships->pluck('organization')->filter()->values(),
            'organization' => null,
            'membership' => null,
            'eligibility' => $eligibility,
        ];
    }

    public function activeMembershipFor(User $user, int $organizationId): ?OrganizationUser
    {
        $resolved = $this->resolve($user);
        if ($resolved['mode'] === ProductAccountEligibility::MODE_SINGLE
            && $resolved['state'] === 'ready'
            && $resolved['organization']->id === $organizationId) {
            return $resolved['membership'];
        }
        if (in_array($resolved['mode'], [
            ProductAccountEligibility::MODE_LEGACY_MULTI,
            ProductAccountEligibility::MODE_REVIEW_REQUIRED,
            'legacy_passthrough',
        ], true)
            && $resolved['organizations']->contains('id', $organizationId)) {
            return OrganizationUser::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->first();
        }

        return null;
    }

    public function canExplicitlySwitch(User $user, int $organizationId): bool
    {
        $resolved = $this->resolve($user);
        if (! in_array($resolved['mode'], [
            ProductAccountEligibility::MODE_LEGACY_MULTI,
            ProductAccountEligibility::MODE_REVIEW_REQUIRED,
            'legacy_passthrough',
        ], true)) {
            return false;
        }

        return $resolved['organizations']->contains('id', $organizationId);
    }

    private function activeMembershipOrganizations(User $user): Collection
    {
        return $user->organizations()
            ->wherePivot('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->orderBy('organizations.name')
            ->get();
    }

    private function reviewState(User $user, ?ProductAccountEligibility $eligibility = null): array
    {
        $organizations = $this->activeMembershipOrganizations($user);
        if ($organizations->count() === 1) {
            $organization = $organizations->first();

            return [
                'mode' => ProductAccountEligibility::MODE_REVIEW_REQUIRED,
                'state' => 'ready',
                'organizations' => $organizations,
                'organization' => $organization,
                'membership' => OrganizationUser::query()
                    ->where('user_id', $user->id)
                    ->where('organization_id', $organization->id)
                    ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                    ->firstOrFail(),
                'eligibility' => $eligibility,
            ];
        }

        return [
            'mode' => ProductAccountEligibility::MODE_REVIEW_REQUIRED,
            'state' => $organizations->isEmpty() ? 'review_required' : 'selection',
            'organizations' => $organizations,
            'organization' => null,
            'membership' => null,
            'eligibility' => $eligibility,
        ];
    }

    private function state(string $state, ?ProductAccountEligibility $eligibility = null): array
    {
        return [
            'mode' => $eligibility?->mode ?? ProductAccountEligibility::MODE_REVIEW_REQUIRED,
            'state' => $state,
            'organizations' => collect(),
            'organization' => null,
            'membership' => null,
            'eligibility' => $eligibility,
        ];
    }
}
