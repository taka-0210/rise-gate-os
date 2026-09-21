<?php

namespace App\Services\ProductOrganization;

use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\ProductOrganizationCompatibility;
use App\Models\User;
use App\Services\AccountAudit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductOrganizationInventory
{
    public function __construct(private readonly AccountAudit $audit) {}

    public function classify(User $user, string $evidenceRef): array
    {
        $memberships = OrganizationUser::query()
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'organization_id', 'membership_status', 'created_at']);
        $membershipOrganizationIds = $memberships->pluck('organization_id')->unique()->sort()->values();
        $footprintOrganizationIds = $this->footprintOrganizationIds($user);
        $inconsistentOrganizationIds = $footprintOrganizationIds->diff($membershipOrganizationIds)->values();

        $mode = ProductAccountEligibility::MODE_REVIEW_REQUIRED;
        $productOrganizationId = null;
        if ($inconsistentOrganizationIds->isEmpty() && $membershipOrganizationIds->count() === 1) {
            $mode = ProductAccountEligibility::MODE_SINGLE;
            $productOrganizationId = (int) $membershipOrganizationIds->first();
        } elseif ($inconsistentOrganizationIds->isEmpty() && $membershipOrganizationIds->count() > 1) {
            $mode = ProductAccountEligibility::MODE_LEGACY_MULTI;
        }

        $fingerprint = hash('sha256', json_encode([
            'user_id' => $user->id,
            'memberships' => $memberships->map(fn (OrganizationUser $membership): array => [
                'id' => $membership->id,
                'organization_id' => $membership->organization_id,
                'status' => $membership->membership_status,
            ])->all(),
            'footprint_organization_ids' => $footprintOrganizationIds->all(),
            'mode' => $mode,
            'product_organization_id' => $productOrganizationId,
            'version' => config('product_ux.classification_version'),
        ], JSON_THROW_ON_ERROR));

        return [
            'user_id' => $user->id,
            'mode' => $mode,
            'product_organization_id' => $productOrganizationId,
            'membership_ids' => $memberships->pluck('id')->all(),
            'membership_statuses' => $memberships->pluck('membership_status')->countBy()->all(),
            'organization_count' => $membershipOrganizationIds->count(),
            'inconsistent_organization_count' => $inconsistentOrganizationIds->count(),
            'classification_version' => config('product_ux.classification_version'),
            'classified_at' => now(),
            'evidence_ref' => trim($evidenceRef).':'.$fingerprint,
            'fingerprint' => $fingerprint,
        ];
    }

    public function apply(User $user, string $evidenceRef): ProductAccountEligibility
    {
        return DB::transaction(function () use ($user, $evidenceRef): ProductAccountEligibility {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = ProductAccountEligibility::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing->load('compatibilities');
            }

            $classification = $this->classify($user, $evidenceRef);
            $eligibility = ProductAccountEligibility::query()->create([
                'user_id' => $user->id,
                'mode' => $classification['mode'],
                'product_organization_id' => $classification['product_organization_id'],
                'classification_version' => $classification['classification_version'],
                'classified_at' => $classification['classified_at'],
                'evidence_ref' => $classification['evidence_ref'],
            ]);

            if ($classification['mode'] === ProductAccountEligibility::MODE_LEGACY_MULTI) {
                $memberships = OrganizationUser::query()
                    ->where('user_id', $user->id)
                    ->whereIn('id', $classification['membership_ids'])
                    ->lockForUpdate()
                    ->get();
                foreach ($memberships as $membership) {
                    if ($membership->user_id !== $eligibility->user_id) {
                        throw new \LogicException('Compatibility membership user mismatch.');
                    }
                    ProductOrganizationCompatibility::query()->create([
                        'product_account_eligibility_id' => $eligibility->id,
                        'organization_user_id' => $membership->id,
                        'cutoff_at' => $classification['classified_at'],
                        'evidence_ref' => $classification['evidence_ref'],
                    ]);
                }
            }

            $this->audit->record(
                'account.product_organization.classified',
                'success',
                $user,
                null,
                [
                    'mode' => $classification['mode'],
                    'organization_count' => $classification['organization_count'],
                    'inconsistent_organization_count' => $classification['inconsistent_organization_count'],
                    'classification_version' => $classification['classification_version'],
                    'evidence_ref' => $classification['evidence_ref'],
                    'timezone' => config('app.timezone'),
                ],
            );

            return $eligibility->load('compatibilities');
        }, 3);
    }

    public function dryRun(string $evidenceRef): array
    {
        $rows = User::query()->orderBy('id')->get()->map(
            fn (User $user): array => $this->classify($user, $evidenceRef),
        );

        return [
            'classification_version' => config('product_ux.classification_version'),
            'user_count' => $rows->count(),
            'mode_counts' => $rows->pluck('mode')->countBy()->sortKeys()->all(),
            'checksum' => hash('sha256', $rows->pluck('fingerprint')->implode('|')),
            'rows' => $rows->all(),
        ];
    }

    private function footprintOrganizationIds(User $user): Collection
    {
        $workspaceIds = DB::table('workspace_members')->where('user_id', $user->id)->pluck('workspace_id')
            ->merge(DB::table('workspaces')->where('owner_user_id', $user->id)->pluck('id'))
            ->merge(DB::table('project_members')->where('user_id', $user->id)->pluck('workspace_id'))
            ->filter()->unique();

        return DB::table('workspaces')->whereIn('id', $workspaceIds)->pluck('organization_id')
            ->merge(DB::table('owner_onboardings')->where('claimed_user_id', $user->id)->whereNotNull('completed_organization_id')->pluck('completed_organization_id'))
            ->filter()->unique()->sort()->values();
    }
}
