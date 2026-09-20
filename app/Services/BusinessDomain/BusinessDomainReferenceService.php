<?php

namespace App\Services\BusinessDomain;

use App\Models\BusinessDomain;
use App\Models\BusinessDomainRevision;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class BusinessDomainReferenceService
{
    public function __construct(
        private readonly BusinessDomainAccess $access,
        private readonly BusinessDomainSnapshot $snapshots,
    ) {}

    public function get(
        User $actor,
        Organization $organization,
        string $domainPublicId,
        string $purpose,
        ?int $revisionNo = null,
    ): array {
        if (trim($purpose) === '' || mb_strlen($purpose) > 100) {
            throw ValidationException::withMessages(['purpose' => '参照目的を100文字以内で指定してください。']);
        }
        $this->access->authorizeView($actor, $organization);
        $domain = BusinessDomain::query()
            ->where('organization_id', $organization->id)
            ->where('public_id', $domainPublicId)
            ->first();
        if (! $domain) {
            throw (new ModelNotFoundException)->setModel(BusinessDomain::class);
        }

        if ($revisionNo !== null) {
            $this->access->authorizeHistory($actor, $organization);
            $revision = BusinessDomainRevision::query()
                ->where('business_domain_id', $domain->id)
                ->where('revision_no', $revisionNo)
                ->first();
            if (! $revision) {
                throw (new ModelNotFoundException)->setModel(BusinessDomainRevision::class);
            }
            $snapshot = $revision->snapshot;
        } else {
            $snapshot = $this->snapshots->make($domain);
        }

        return [
            'contract' => 'company_os.business_domain_reference',
            'contract_version' => 1,
            'source' => 'organization_self_reported',
            'organization_public_id' => $organization->public_id,
            'domain_public_id' => $domain->public_id,
            'revision' => $revisionNo ?? $domain->version,
            'purpose' => $purpose,
            'data' => $snapshot,
        ];
    }
}
