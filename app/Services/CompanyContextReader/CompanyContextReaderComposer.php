<?php

namespace App\Services\CompanyContextReader;

use App\Models\ManagementDesignItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class CompanyContextReaderComposer
{
    public function __construct(
        private readonly ManagementDesignOfficialResolver $managementDesign,
        private readonly AnnualPolicyReaderResolver $annualPolicy,
    ) {}

    public function compose(User $actor, Organization $organization, ?string $annualSelector = null): CompanyContextReaderData
    {
        $chapters = [];
        foreach (ManagementDesignItem::TYPES as $index => $type) {
            $chapter = $this->managementDesign->resolve($actor, $organization, $type, $index + 1);
            if ($chapter) {
                $chapters[] = $chapter;
            }
        }

        $annual = $this->annualPolicy->resolve($actor, $organization, $annualSelector);
        if ($annual['chapter']) {
            $chapters[] = $annual['chapter'];
        }
        if ($chapters === []) {
            throw new AuthorizationException;
        }

        return new CompanyContextReaderData($chapters, $annual['options'], $annual['selected']);
    }
}
