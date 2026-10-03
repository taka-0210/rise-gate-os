<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\AnnualManagementPolicyOperation;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationManagementPeriod;
use App\Models\OrganizationManagementPeriodVersion;
use App\Models\User;
use App\Services\Organization\OrganizationAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagementPeriodWriter
{
    public function __construct(
        private readonly AnnualManagementPolicyAccess $access,
        private readonly OrganizationAudit $audit,
    ) {}

    public function register(
        User $actor,
        Organization $organization,
        string $name,
        string $startsOn,
        string $endsOn,
        string $requestId,
    ): OrganizationManagementPeriod {
        return $this->write($actor, $organization, null, $name, $startsOn, $endsOn, 0, null, $requestId);
    }

    public function correct(
        User $actor,
        Organization $organization,
        OrganizationManagementPeriod $period,
        string $name,
        string $startsOn,
        string $endsOn,
        int $expectedVersion,
        ?string $reason,
        string $requestId,
    ): OrganizationManagementPeriod {
        return $this->write($actor, $organization, $period, $name, $startsOn, $endsOn, $expectedVersion, $reason, $requestId);
    }

    private function write(
        User $actor,
        Organization $organization,
        ?OrganizationManagementPeriod $period,
        string $name,
        string $startsOn,
        string $endsOn,
        int $expectedVersion,
        ?string $reason,
        string $requestId,
    ): OrganizationManagementPeriod {
        return DB::transaction(function () use ($actor, $organization, $period, $name, $startsOn, $endsOn, $expectedVersion, $reason, $requestId): OrganizationManagementPeriod {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeManage($actor, $organization, true);
            $name = trim($name);
            $start = CarbonImmutable::parse($startsOn, 'Asia/Tokyo')->startOfDay();
            $end = CarbonImmutable::parse($endsOn, 'Asia/Tokyo')->startOfDay();
            if ($name === '' || $start->gt($end)) {
                throw ValidationException::withMessages(['period' => '期間名と開始・終了日を確認してください。']);
            }
            $payload = compact('name', 'startsOn', 'endsOn', 'expectedVersion', 'reason');
            $operationName = $period ? 'period_correct' : 'period_register';
            $hash = $this->hash($operationName, $payload);
            if ($existing = $this->completedOperation($organization, $actor, $requestId, $operationName, $hash)) {
                return OrganizationManagementPeriod::query()->where('public_id', $existing->result_metadata['period_public_id'])->firstOrFail();
            }

            $locked = $period
                ? OrganizationManagementPeriod::query()->where('organization_id', $organization->id)->lockForUpdate()->findOrFail($period->id)
                : null;
            if (($locked ? (int) $locked->version : 0) !== $expectedVersion) {
                throw ValidationException::withMessages(['expected_version' => '期間が更新されています。読み直してください。']);
            }
            $overlap = OrganizationManagementPeriod::query()
                ->where('organization_id', $organization->id)
                ->when($locked, fn ($query) => $query->whereKeyNot($locked->id))
                ->whereDate('starts_on', '<=', $end->toDateString())
                ->whereDate('ends_on', '>=', $start->toDateString())
                ->lockForUpdate()
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['period' => '同じ会社の期間は重ねられません。']);
            }

            $before = $locked ? ['version' => (int) $locked->version] : null;
            $locked ??= new OrganizationManagementPeriod([
                'organization_id' => $organization->id,
                'created_by_user_id' => $actor->id,
            ]);
            $locked->fill([
                'name' => $name, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(),
                'version' => $expectedVersion + 1, 'updated_by_user_id' => $actor->id,
            ])->save();
            OrganizationManagementPeriodVersion::create([
                'organization_management_period_id' => $locked->id,
                'version_no' => $locked->version,
                'name' => $locked->name,
                'starts_on' => $locked->starts_on,
                'ends_on' => $locked->ends_on,
                'actor_user_id' => $actor->id,
                'change_reason' => $this->nullable($reason),
                'changed_at' => now(),
            ]);
            $operation = AnnualManagementPolicyOperation::create([
                'organization_id' => $organization->id, 'actor_user_id' => $actor->id,
                'request_id' => $requestId, 'payload_hash' => $hash, 'operation' => $operationName,
                'result_metadata' => ['period_public_id' => $locked->public_id, 'period_version' => $locked->version],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $organization, $actor, 'annual_management_policy.'.$operationName,
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: $before,
                after: ['version' => (int) $locked->version],
                metadata: ['period_public_id' => $locked->public_id, 'operation_id' => $operation->id, 'request_id' => $requestId],
            );

            return $locked->fresh('versions');
        }, 5);
    }

    private function completedOperation(Organization $organization, User $actor, string $requestId, string $name, string $hash): ?AnnualManagementPolicyOperation
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

    private function hash(string $name, array $payload): string
    {
        return hash('sha256', json_encode([$name, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
