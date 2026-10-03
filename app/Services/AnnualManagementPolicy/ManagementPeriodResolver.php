<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\Organization;
use App\Models\OrganizationManagementPeriod;
use Carbon\CarbonImmutable;
use LogicException;

class ManagementPeriodResolver
{
    public function current(Organization $organization, CarbonImmutable|string|null $date = null): ?OrganizationManagementPeriod
    {
        $day = $date instanceof CarbonImmutable
            ? $date->setTimezone('Asia/Tokyo')->toDateString()
            : ($date ?: CarbonImmutable::now('Asia/Tokyo')->toDateString());
        $periods = OrganizationManagementPeriod::query()
            ->where('organization_id', $organization->id)
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            ->orderBy('id')
            ->get();
        if ($periods->count() > 1) {
            throw new LogicException('Management period overlap detected.');
        }

        return $periods->first();
    }
}
