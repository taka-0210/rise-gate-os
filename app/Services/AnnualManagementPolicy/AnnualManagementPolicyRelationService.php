<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\AnnualManagementPolicy;
use App\Models\AnnualManagementPolicyDepartment;
use App\Models\AnnualManagementPolicyOperation;
use App\Models\AnnualManagementPolicyPriority;
use App\Models\AnnualManagementPolicyRelation;
use App\Models\AnnualManagementPolicyRelationVersion;
use App\Models\AnnualManagementPolicyTheme;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\ActionExecution\ActionExecutionAccess;
use App\Services\Organization\OrganizationAudit;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnualManagementPolicyRelationService
{
    public const SOURCE_TYPES = ['annual', 'theme', 'priority', 'department'];
    public const TARGET_TYPES = ['project', 'action', 'group', 'department'];

    private const ALLOWED = [
        'annual' => ['project', 'action'],
        'theme' => ['project', 'action'],
        'priority' => ['project', 'action', 'group', 'department'],
        'department' => ['project', 'action'],
    ];

    public function __construct(
        private readonly AnnualManagementPolicyAccess $access,
        private readonly ProjectExecutionAccess $projects,
        private readonly ActionExecutionAccess $actions,
        private readonly OrganizationAudit $audit,
    ) {}

    public function confirm(
        User $actor,
        AnnualManagementPolicy $policy,
        string $sourceType,
        string $sourcePublicId,
        string $targetType,
        string $targetPublicId,
        int $expectedRelationVersion,
        ?string $reason,
        string $requestId,
    ): AnnualManagementPolicyRelation {
        return $this->write($actor, $policy, $sourceType, $sourcePublicId, $targetType, $targetPublicId, $expectedRelationVersion, AnnualManagementPolicyRelation::STATUS_CONFIRMED, $reason, $requestId);
    }

    public function withdraw(
        User $actor,
        AnnualManagementPolicy $policy,
        AnnualManagementPolicyRelation $relation,
        int $expectedRelationVersion,
        ?string $reason,
        string $requestId,
    ): AnnualManagementPolicyRelation {
        if ($relation->annual_management_policy_id !== $policy->id) {
            throw new AuthorizationException;
        }
        return $this->write(
            $actor, $policy, $relation->source_type, $relation->source_public_id,
            $relation->target_type, $relation->target_public_id, $expectedRelationVersion,
            AnnualManagementPolicyRelation::STATUS_WITHDRAWN, $reason, $requestId,
        );
    }

    public function readableTarget(User $actor, Organization $organization, string $type, string $publicId): object
    {
        return match ($type) {
            'project' => $this->projectTarget($actor, $organization, $publicId),
            'action' => $this->actionTarget($actor, $organization, $publicId),
            'group' => $this->groupTarget($organization, $publicId),
            'department' => $this->departmentTarget($organization, $publicId),
            default => throw ValidationException::withMessages(['target_type' => '許可されていない関連先です。']),
        };
    }

    private function write(
        User $actor,
        AnnualManagementPolicy $policy,
        string $sourceType,
        string $sourcePublicId,
        string $targetType,
        string $targetPublicId,
        int $expectedRelationVersion,
        string $status,
        ?string $reason,
        string $requestId,
    ): AnnualManagementPolicyRelation {
        return DB::transaction(function () use (
            $actor, $policy, $sourceType, $sourcePublicId, $targetType, $targetPublicId,
            $expectedRelationVersion, $status, $reason, $requestId,
        ): AnnualManagementPolicyRelation {
            $policy = AnnualManagementPolicy::query()->with('organization')->lockForUpdate()->findOrFail($policy->id);
            $this->access->authorizeEdit($actor, $policy, true);
            $this->assertPair($sourceType, $targetType);
            $this->source($policy, $sourceType, $sourcePublicId);
            $this->readableTarget($actor, $policy->organization, $targetType, $targetPublicId);
            if ((int) $policy->relation_version !== $expectedRelationVersion) {
                throw ValidationException::withMessages(['expected_relation_version' => '関連付けが更新されています。読み直してください。']);
            }
            $payload = compact('sourceType', 'sourcePublicId', 'targetType', 'targetPublicId', 'expectedRelationVersion', 'status', 'reason');
            $hash = $this->hash('relation_'.$status, $payload);
            if ($existing = $this->completedOperation($policy->organization, $actor, $requestId, 'relation_'.$status, $hash)) {
                return AnnualManagementPolicyRelation::query()->findOrFail($existing->result_metadata['relation_id']);
            }
            $relation = AnnualManagementPolicyRelation::query()->firstOrNew([
                'annual_management_policy_id' => $policy->id,
                'source_type' => $sourceType,
                'source_public_id' => $sourcePublicId,
                'target_type' => $targetType,
                'target_public_id' => $targetPublicId,
            ], ['organization_id' => $policy->organization_id]);
            if ($relation->exists && $relation->current_status === $status) {
                throw ValidationException::withMessages(['relation' => '関連付けはすでに同じ状態です。']);
            }
            $relation->current_version = ((int) $relation->current_version) + 1;
            $relation->current_status = $status;
            $relation->save();
            AnnualManagementPolicyRelationVersion::create([
                'annual_management_policy_relation_id' => $relation->id,
                'version_no' => $relation->current_version,
                'status' => $status,
                'confirmed_by_user_id' => $actor->id,
                'reason' => $this->nullable($reason),
                'confirmed_at' => now(),
            ]);
            $policy->increment('relation_version');
            $operation = AnnualManagementPolicyOperation::create([
                'organization_id' => $policy->organization_id, 'actor_user_id' => $actor->id,
                'request_id' => $requestId, 'payload_hash' => $hash, 'operation' => 'relation_'.$status,
                'annual_management_policy_id' => $policy->id,
                'result_metadata' => ['relation_id' => $relation->id, 'relation_public_id' => $relation->public_id],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $policy->organization, $actor, 'annual_management_policy.relation_'.$status, OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: ['relation_version' => $expectedRelationVersion],
                after: ['relation_version' => $expectedRelationVersion + 1, 'relation_status' => $status],
                metadata: ['annual_public_id' => $policy->public_id, 'relation_public_id' => $relation->public_id, 'operation_id' => $operation->id, 'request_id' => $requestId],
            );

            return $relation->fresh('versions');
        }, 5);
    }

    private function source(AnnualManagementPolicy $policy, string $type, string $publicId): object
    {
        return match ($type) {
            'annual' => $policy->public_id === $publicId ? $policy : throw new AuthorizationException,
            'theme' => AnnualManagementPolicyTheme::query()->where('annual_management_policy_id', $policy->id)->where('public_id', $publicId)->firstOrFail(),
            'priority' => AnnualManagementPolicyPriority::query()->where('annual_management_policy_id', $policy->id)->where('public_id', $publicId)->firstOrFail(),
            'department' => AnnualManagementPolicyDepartment::query()->where('annual_management_policy_id', $policy->id)->where('public_id', $publicId)->firstOrFail(),
            default => throw ValidationException::withMessages(['source_type' => '許可されていない関連元です。']),
        };
    }

    private function projectTarget(User $actor, Organization $organization, string $publicId): Project
    {
        $project = Project::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
        if (! $this->projects->canRead($actor, $project)) { throw new AuthorizationException; }
        return $project;
    }

    private function actionTarget(User $actor, Organization $organization, string $publicId): Task
    {
        $action = Task::query()->with('project')->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
        if (! $this->actions->canRead($actor, $action)) { throw new AuthorizationException; }
        return $action;
    }

    private function groupTarget(Organization $organization, string $publicId): OrganizationGroup
    {
        return OrganizationGroup::query()->where('organization_id', $organization->id)
            ->where('public_id', $publicId)->whereNull('archived_at')->firstOrFail();
    }

    private function departmentTarget(Organization $organization, string $publicId): AnnualManagementPolicyDepartment
    {
        return AnnualManagementPolicyDepartment::query()->where('public_id', $publicId)
            ->whereHas('annualPolicy', fn ($query) => $query->where('organization_id', $organization->id))->firstOrFail();
    }

    private function assertPair(string $sourceType, string $targetType): void
    {
        if (! in_array($targetType, self::ALLOWED[$sourceType] ?? [], true)) {
            throw ValidationException::withMessages(['relation' => 'この種類の関連付けは許可されていません。']);
        }
    }

    private function completedOperation(Organization $organization, User $actor, string $requestId, string $name, string $hash): ?AnnualManagementPolicyOperation
    {
        $existing = AnnualManagementPolicyOperation::query()->where('organization_id', $organization->id)
            ->where('actor_user_id', $actor->id)->where('request_id', $requestId)->lockForUpdate()->first();
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

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
