<?php

namespace Tests;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\Organization\OrganizationSessionContext;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    protected function establishSingleProductOrganization(User $user, Organization $organization): ProductAccountEligibility
    {
        $eligibility = ProductAccountEligibility::query()->where('user_id', $user->id)->first();
        if ($eligibility !== null) {
            if ($eligibility->mode !== ProductAccountEligibility::MODE_SINGLE
                || $eligibility->product_organization_id !== $organization->id) {
                throw new LogicException('Fixture attempted to overwrite an existing Product Organization contract.');
            }
        } else {
            $eligibility = ProductAccountEligibility::query()->create([
                'user_id' => $user->id,
                'mode' => ProductAccountEligibility::MODE_SINGLE,
                'product_organization_id' => $organization->id,
                'classification_version' => 'test-fixture-single-v1',
                'classified_at' => now(),
                'evidence_ref' => 'test:explicit-single-fixture',
            ]);
        }

        $membership = OrganizationUser::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->firstOrFail();
        $this->withSession([
            OrganizationSessionContext::COMPANY_ID => $organization->id,
            OrganizationSessionContext::ACCESS_EPOCH => $membership->access_epoch,
        ]);

        return $eligibility;
    }

    protected function productOrganizationSession(User $user, Organization $organization): array
    {
        $membership = OrganizationUser::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->firstOrFail();

        return [
            OrganizationSessionContext::COMPANY_ID => $organization->id,
            OrganizationSessionContext::ACCESS_EPOCH => $membership->access_epoch,
        ];
    }

    protected function establishUnstartedProductAccount(User $user): ProductAccountEligibility
    {
        $eligibility = app(ProductOrganizationAdmission::class)
            ->registerUnstarted($user, 'test:explicit-unstarted-fixture');

        if ($eligibility === null) {
            throw new LogicException('The Product Organization Admission gate must be enabled for this fixture.');
        }

        return $eligibility;
    }
}
