<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\AnnualManagementPolicy;
use App\Models\AnnualManagementPolicyGrant;
use App\Models\AnnualManagementPolicyOperation;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationManagementPeriod;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnualManagementPolicyPermissionManager
{
    public function __construct(
        private readonly AnnualManagementPolicyAccess $access,
        private readonly OrganizationAudit $audit,
    ) {}

    public function initialize(
        User $actor,
        Organization $organization,
        OrganizationManagementPeriod $period,
        string $requestId,
    ): AnnualManagementPolicy {
        return DB::transaction(function () use ($actor, $organization, $period, $requestId): AnnualManagementPolicy {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeManage($actor, $organization, true);
            $period = OrganizationManagementPeriod::query()
                ->where('organization_id', $organization->id)->lockForUpdate()->findOrFail($period->id);
            $hash = $this->hash('annual_initialize', ['period_public_id' => $period->public_id]);
            if ($existing = $this->operation($organization, $actor, $requestId, 'annual_initialize', $hash)) {
                return AnnualManagementPolicy::query()->findOrFail($existing->annual_management_policy_id);
            }
            $policy = AnnualManagementPolicy::query()
                ->where('organization_id', $organization->id)
                ->where('organization_management_period_id', $period->id)
                ->lockForUpdate()->first();
            $created = ! $policy;
            $policy ??= AnnualManagementPolicy::create([
                'organization_id' => $organization->id,
                'organization_management_period_id' => $period->id,
                'draft_period_name' => $period->name,
                'draft_starts_on' => $period->starts_on,
                'draft_ends_on' => $period->ends_on,
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $operation = AnnualManagementPolicyOperation::create([
                'organization_id' => $organization->id, 'actor_user_id' => $actor->id,
                'request_id' => $requestId, 'payload_hash' => $hash, 'operation' => 'annual_initialize',
                'annual_management_policy_id' => $policy->id,
                'result_metadata' => ['annual_public_id' => $policy->public_id, 'created' => $created],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $organization, $actor, 'annual_management_policy.initialize', OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: null, after: ['created' => $created],
                metadata: ['annual_public_id' => $policy->public_id, 'operation_id' => $operation->id, 'request_id' => $requestId],
            );

            return $policy->fresh(['period', 'grants']);
        }, 5);
    }

    public function update(
        User $actor,
        Organization $organization,
        AnnualManagementPolicy $policy,
        string $approvedViewScope,
        array $submittedGrants,
        string $requestId,
    ): AnnualManagementPolicy {
        return DB::transaction(function () use ($actor, $organization, $policy, $approvedViewScope, $submittedGrants, $requestId): AnnualManagementPolicy {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeManage($actor, $organization, true);
            $policy = AnnualManagementPolicy::query()->where('organization_id', $organization->id)->lockForUpdate()->findOrFail($policy->id);
            $this->validate($approvedViewScope, $submittedGrants);
            $normalized = collect($submittedGrants)->sortKeys()->all();
            $hash = $this->hash('permissions_update', ['annual' => $policy->public_id, 'scope' => $approvedViewScope, 'grants' => $normalized]);
            if ($this->operation($organization, $actor, $requestId, 'permissions_update', $hash)) {
                return $policy->fresh('grants');
            }
            $membershipIds = array_map('intval', array_keys($normalized));
            $memberships = OrganizationUser::query()
                ->where('organization_id', $organization->id)
                ->whereIn('id', $membershipIds)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->lockForUpdate()->get()->keyBy('id');
            if ($memberships->count() !== count(array_unique($membershipIds))) {
                throw ValidationException::withMessages(['grants' => '現在利用中の所属だけを指定できます。']);
            }
            $beforeScope = $policy->approved_view_scope;
            $before = $this->counts($policy);
            $policy->update(['approved_view_scope' => $approvedViewScope, 'updated_by_user_id' => $actor->id]);
            $existing = AnnualManagementPolicyGrant::query()
                ->where('annual_management_policy_id', $policy->id)->lockForUpdate()->get()->keyBy('organization_user_id');
            foreach ($existing as $grant) {
                $grant->update([
                    'can_view_approved' => false, 'can_view_draft' => false,
                    'can_edit' => false, 'can_approve' => false, 'updated_by_user_id' => $actor->id,
                ]);
            }
            foreach ($normalized as $membershipId => $permissions) {
                $grant = $existing->get((int) $membershipId) ?? new AnnualManagementPolicyGrant([
                    'annual_management_policy_id' => $policy->id,
                    'organization_user_id' => (int) $membershipId,
                ]);
                $grant->fill([
                    'can_view_approved' => (bool) ($permissions['can_view_approved'] ?? false),
                    'can_view_draft' => (bool) ($permissions['can_view_draft'] ?? false),
                    'can_edit' => (bool) ($permissions['can_edit'] ?? false),
                    'can_approve' => (bool) ($permissions['can_approve'] ?? false),
                    'updated_by_user_id' => $actor->id,
                ])->save();
            }
            $after = $this->counts($policy);
            $operation = AnnualManagementPolicyOperation::create([
                'organization_id' => $organization->id, 'actor_user_id' => $actor->id,
                'request_id' => $requestId, 'payload_hash' => $hash, 'operation' => 'permissions_update',
                'annual_management_policy_id' => $policy->id,
                'result_metadata' => ['annual_public_id' => $policy->public_id, 'scope' => $approvedViewScope],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $organization, $actor, 'annual_management_policy.permissions_update', OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: ['scope' => $beforeScope, 'counts' => $before],
                after: ['scope' => $approvedViewScope, 'counts' => $after],
                metadata: ['annual_public_id' => $policy->public_id, 'operation_id' => $operation->id, 'request_id' => $requestId],
            );

            return $policy->fresh('grants');
        }, 5);
    }

    private function validate(string $scope, array $grants): void
    {
        if (! in_array($scope, AnnualManagementPolicy::VIEW_SCOPES, true)) {
            throw ValidationException::withMessages(['approved_view_scope' => '共有範囲が正しくありません。']);
        }
        foreach ($grants as $membershipId => $permission) {
            if (! ctype_digit((string) $membershipId) || ! is_array($permission)) {
                throw ValidationException::withMessages(['grants' => '権限指定が正しくありません。']);
            }
            $draftView = (bool) ($permission['can_view_draft'] ?? false);
            if (((bool) ($permission['can_edit'] ?? false) || (bool) ($permission['can_approve'] ?? false)) && ! $draftView) {
                throw ValidationException::withMessages(['grants' => '編集・承認担当には作成中の案の閲覧権限が必要です。']);
            }
        }
    }

    private function counts(AnnualManagementPolicy $policy): array
    {
        $query = AnnualManagementPolicyGrant::query()->where('annual_management_policy_id', $policy->id);
        return [
            'approved_viewers' => (clone $query)->where('can_view_approved', true)->count(),
            'draft_viewers' => (clone $query)->where('can_view_draft', true)->count(),
            'editors' => (clone $query)->where('can_edit', true)->count(),
            'approvers' => (clone $query)->where('can_approve', true)->count(),
        ];
    }

    private function operation(Organization $organization, User $actor, string $requestId, string $name, string $hash): ?AnnualManagementPolicyOperation
    {
        $existing = AnnualManagementPolicyOperation::query()
            ->where('organization_id', $organization->id)->where('actor_user_id', $actor->id)
            ->where('request_id', $requestId)->lockForUpdate()->first();
        if (! $existing) { return null; }
        if ($existing->operation !== $name || ! hash_equals($existing->payload_hash, $hash) || ! $existing->completed_at) {
            throw ValidationException::withMessages(['request_id' => '同じ操作IDを異なる内容には使用できません。']);
        }
        return $existing;
    }

    private function hash(string $operation, array $payload): string
    {
        return hash('sha256', json_encode([$operation, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
