<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\AnnualManagementPolicy;
use App\Models\AnnualManagementPolicyDepartment;
use App\Models\AnnualManagementPolicyDepartmentStatement;
use App\Models\AnnualManagementPolicyOperation;
use App\Models\AnnualManagementPolicyPriority;
use App\Models\AnnualManagementPolicyRevision;
use App\Models\AnnualManagementPolicyTheme;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\User;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AnnualManagementPolicyWriter
{
    public function __construct(
        private readonly AnnualManagementPolicyAccess $access,
        private readonly AnnualManagementPolicySnapshot $snapshots,
        private readonly OrganizationAudit $audit,
    ) {}

    public function preview(User $actor, AnnualManagementPolicy $policy): array
    {
        $policy = AnnualManagementPolicy::query()->with('organization')->findOrFail($policy->id);
        $this->access->authorizeDraftView($actor, $policy);
        $snapshot = $this->snapshots->make($policy);

        return ['snapshot' => $snapshot, 'snapshot_hash' => $this->snapshots->hash($snapshot)];
    }

    public function saveDraft(
        User $actor,
        AnnualManagementPolicy $policy,
        array $input,
        int $expectedDraftVersion,
        string $requestId,
    ): AnnualManagementPolicy {
        return DB::transaction(function () use ($actor, $policy, $input, $expectedDraftVersion, $requestId): AnnualManagementPolicy {
            $policy = AnnualManagementPolicy::query()->with('organization')->lockForUpdate()->findOrFail($policy->id);
            $this->access->authorizeEdit($actor, $policy, true);
            $payload = ['annual_public_id' => $policy->public_id, 'input' => $input, 'expected_draft_version' => $expectedDraftVersion];
            $hash = $this->hash('draft_save', $payload);
            if ($existing = $this->completedOperation($policy->organization, $actor, $requestId, 'draft_save', $hash)) {
                return $this->fromOperation($existing);
            }
            if ((int) $policy->draft_version !== $expectedDraftVersion) {
                throw ValidationException::withMessages(['expected_draft_version' => '作成中の案が更新されています。読み直してください。']);
            }
            $this->validateDraftShape($input);
            $operation = AnnualManagementPolicyOperation::create([
                'organization_id' => $policy->organization_id, 'actor_user_id' => $actor->id,
                'request_id' => $requestId, 'payload_hash' => $hash, 'operation' => 'draft_save',
                'annual_management_policy_id' => $policy->id,
            ]);
            $beforeVersion = (int) $policy->draft_version;
            $policy->fill([
                'draft_period_name' => $this->nullable($input['period_name'] ?? null),
                'draft_starts_on' => $input['starts_on'] ?? null,
                'draft_ends_on' => $input['ends_on'] ?? null,
                'draft_purpose' => $this->nullable($input['purpose'] ?? null),
                'draft_background' => $this->nullable($input['background'] ?? null),
                'draft_policy' => $this->nullable($input['policy'] ?? null),
                'draft_version' => $beforeVersion + 1,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->syncThemes($policy, $input['themes'] ?? []);
            $this->syncDepartments($policy, $input['departments'] ?? []);
            $this->checkpoint('after_draft_values');
            $operation->update([
                'result_metadata' => ['annual_public_id' => $policy->public_id, 'draft_version' => $policy->draft_version],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $policy->organization, $actor, 'annual_management_policy.draft_save', OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: ['draft_version' => $beforeVersion], after: ['draft_version' => (int) $policy->draft_version],
                metadata: ['annual_public_id' => $policy->public_id, 'operation_id' => $operation->id, 'request_id' => $requestId],
            );
            $this->checkpoint('after_draft_audit');

            return $this->load($policy);
        }, 5);
    }

    public function approve(
        User $actor,
        AnnualManagementPolicy $policy,
        int $expectedDraftVersion,
        ?int $expectedBaseRevisionNo,
        int $expectedRelationVersion,
        string $expectedSnapshotHash,
        bool $periodDifferenceConfirmed,
        ?string $changeReason,
        string $requestId,
    ): AnnualManagementPolicyRevision {
        return DB::transaction(function () use (
            $actor, $policy, $expectedDraftVersion, $expectedBaseRevisionNo, $expectedRelationVersion,
            $expectedSnapshotHash, $periodDifferenceConfirmed, $changeReason, $requestId,
        ): AnnualManagementPolicyRevision {
            $policy = AnnualManagementPolicy::query()->with('organization')->lockForUpdate()->findOrFail($policy->id);
            $this->access->authorizeApprove($actor, $policy, true);
            $payload = [
                'annual_public_id' => $policy->public_id,
                'expected_draft_version' => $expectedDraftVersion,
                'expected_base_revision_no' => $expectedBaseRevisionNo,
                'expected_relation_version' => $expectedRelationVersion,
                'expected_snapshot_hash' => $expectedSnapshotHash,
                'period_difference_confirmed' => $periodDifferenceConfirmed,
                'change_reason' => $this->nullable($changeReason),
            ];
            $hash = $this->hash('approve', $payload);
            if ($existing = $this->completedOperation($policy->organization, $actor, $requestId, 'approve', $hash)) {
                return AnnualManagementPolicyRevision::query()
                    ->where('annual_management_policy_id', $policy->id)
                    ->where('revision_no', $existing->result_revision_no)->firstOrFail();
            }
            $currentRevisionNo = $policy->currentApprovedRevision?->revision_no;
            if ((int) $policy->draft_version !== $expectedDraftVersion
                || $currentRevisionNo !== $expectedBaseRevisionNo
                || (int) $policy->base_approved_revision_no !== (int) ($expectedBaseRevisionNo ?? 0)
                || (int) $policy->relation_version !== $expectedRelationVersion) {
                throw ValidationException::withMessages(['approval' => '確認後に内容・正式版・関連付けが更新されています。読み直してください。']);
            }
            $policy->relations()->lockForUpdate()->get();
            $this->validateApproval($policy, $periodDifferenceConfirmed);
            $snapshot = $this->snapshots->make($policy);
            $snapshotHash = $this->snapshots->hash($snapshot);
            if (! hash_equals($snapshotHash, $expectedSnapshotHash)) {
                throw ValidationException::withMessages(['snapshot_hash' => '確認した全体内容と現在の内容が一致しません。']);
            }
            $revisionNo = ((int) (AnnualManagementPolicyRevision::query()
                ->where('annual_management_policy_id', $policy->id)->lockForUpdate()->max('revision_no') ?? 0)) + 1;
            $operation = AnnualManagementPolicyOperation::create([
                'organization_id' => $policy->organization_id, 'actor_user_id' => $actor->id,
                'request_id' => $requestId, 'payload_hash' => $hash, 'operation' => 'approve',
                'annual_management_policy_id' => $policy->id,
            ]);
            $revision = AnnualManagementPolicyRevision::create([
                'annual_management_policy_id' => $policy->id,
                'revision_no' => $revisionNo,
                'snapshot_schema_version' => AnnualManagementPolicySnapshot::SCHEMA_VERSION,
                'snapshot' => $snapshot,
                'snapshot_hash' => $snapshotHash,
                'approved_by_user_id' => $actor->id,
                'annual_management_policy_operation_id' => $operation->id,
                'change_reason' => $this->nullable($changeReason),
                'approved_at' => now(),
            ]);
            $this->checkpoint('after_revision');
            $policy->update([
                'current_approved_revision_id' => $revision->id,
                'base_approved_revision_no' => $revisionNo,
                'updated_by_user_id' => $actor->id,
            ]);
            $operation->update([
                'result_revision_no' => $revisionNo,
                'result_metadata' => ['annual_public_id' => $policy->public_id, 'snapshot_hash' => $snapshotHash],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $policy->organization, $actor, 'annual_management_policy.approve', OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: ['revision_no' => $currentRevisionNo], after: ['revision_no' => $revisionNo],
                metadata: [
                    'annual_public_id' => $policy->public_id, 'snapshot_hash' => $snapshotHash,
                    'operation_id' => $operation->id, 'request_id' => $requestId,
                ],
            );
            $this->checkpoint('after_approval_audit');

            return $revision->fresh(['annualPolicy', 'approver']);
        }, 5);
    }

    protected function checkpoint(string $name): void {}

    private function validateDraftShape(array $input): void
    {
        if (count($input['themes'] ?? []) > 100 || count($input['departments'] ?? []) > 100) {
            throw ValidationException::withMessages(['structure' => 'Theme・部署方針は一度に100件までです。']);
        }
        foreach ($input['themes'] ?? [] as $index => $theme) {
            if (! is_array($theme) || count($theme['priorities'] ?? []) > 100) {
                throw ValidationException::withMessages(["themes.$index" => 'Themeの形式または重点領域数が上限を超えています。']);
            }
        }
        foreach ($input['departments'] ?? [] as $index => $department) {
            if (! is_array($department) || count($department['statements'] ?? []) > 100) {
                throw ValidationException::withMessages(["departments.$index" => '部署方針の形式またはStatement数が上限を超えています。']);
            }
        }
    }

    private function validateApproval(AnnualManagementPolicy $policy, bool $periodDifferenceConfirmed): void
    {
        $policy = $this->load($policy);
        if (! $policy->draft_starts_on || ! $policy->draft_ends_on || ! $policy->draft_period_name
            || $policy->draft_starts_on->gt($policy->draft_ends_on)) {
            throw ValidationException::withMessages(['period' => '正式承認には有効な期間名と開始・終了日が必要です。']);
        }
        if ($this->nullable($policy->draft_policy) === null) {
            throw ValidationException::withMessages(['policy' => '正式承認には年度方針が必要です。']);
        }
        foreach ($policy->themes as $themeIndex => $theme) {
            if ($this->nullable($theme->statement) === null) {
                throw ValidationException::withMessages(["themes.$themeIndex.statement" => '正式承認するThemeにはStatementが必要です。']);
            }
            foreach ($theme->priorities as $priorityIndex => $priority) {
                if ($this->nullable($priority->statement) === null) {
                    throw ValidationException::withMessages(["themes.$themeIndex.priorities.$priorityIndex.statement" => '正式承認する重点領域にはStatementが必要です。']);
                }
            }
        }
        foreach ($policy->departments as $departmentIndex => $department) {
            if ($department->group->organization_id !== $policy->organization_id || $department->statements->isEmpty()) {
                throw ValidationException::withMessages(["departments.$departmentIndex" => '部署方針には同じ会社の部署と1件以上のStatementが必要です。']);
            }
            foreach ($department->statements as $statementIndex => $statement) {
                if ($this->nullable($statement->statement) === null) {
                    throw ValidationException::withMessages(["departments.$departmentIndex.statements.$statementIndex" => '部署方針のStatementを入力してください。']);
                }
            }
        }
        $periodDiffers = $policy->draft_period_name !== $policy->period->name
            || $policy->draft_starts_on->toDateString() !== $policy->period->starts_on->toDateString()
            || $policy->draft_ends_on->toDateString() !== $policy->period->ends_on->toDateString();
        if ($periodDiffers && ! $periodDifferenceConfirmed) {
            throw ValidationException::withMessages(['period_difference_confirmed' => '会社期間との差異を確認してください。']);
        }
    }

    private function syncThemes(AnnualManagementPolicy $policy, array $submitted): void
    {
        $existing = AnnualManagementPolicyTheme::query()->where('annual_management_policy_id', $policy->id)->lockForUpdate()->get()->keyBy('public_id');
        $seen = [];
        foreach (array_values($submitted) as $index => $data) {
            $publicId = $this->nullable($data['public_id'] ?? null);
            if ($publicId && isset($seen[$publicId])) {
                throw ValidationException::withMessages(["themes.$index.public_id" => '同じTheme IDが重複しています。']);
            }
            $theme = $publicId ? $existing->get($publicId) : null;
            if ($publicId && ! $theme) {
                throw ValidationException::withMessages(["themes.$index.public_id" => 'この年度方針のThemeではありません。']);
            }
            $theme ??= new AnnualManagementPolicyTheme(['annual_management_policy_id' => $policy->id]);
            $theme->fill([
                'statement' => $this->nullable($data['statement'] ?? null),
                'explanation' => $this->nullable($data['explanation'] ?? null),
                'sort_order' => $index,
            ])->save();
            $this->syncPriorities($policy, $theme, $data['priorities'] ?? []);
            $seen[$theme->public_id] = true;
        }
        foreach ($existing as $publicId => $theme) {
            if (! isset($seen[$publicId])) { $theme->delete(); }
        }
    }

    private function syncPriorities(AnnualManagementPolicy $policy, AnnualManagementPolicyTheme $theme, array $submitted): void
    {
        $existing = AnnualManagementPolicyPriority::query()
            ->where('annual_management_policy_id', $policy->id)
            ->where('annual_management_policy_theme_id', $theme->id)
            ->lockForUpdate()->get()->keyBy('public_id');
        $seen = [];
        foreach (array_values($submitted) as $index => $data) {
            $publicId = $this->nullable($data['public_id'] ?? null);
            $priority = $publicId ? $existing->get($publicId) : null;
            if ($publicId && (! $priority || isset($seen[$publicId]))) {
                throw ValidationException::withMessages(["priorities.$index.public_id" => '重点領域IDが正しくありません。']);
            }
            $priority ??= new AnnualManagementPolicyPriority([
                'annual_management_policy_id' => $policy->id,
                'annual_management_policy_theme_id' => $theme->id,
            ]);
            $priority->fill([
                'statement' => $this->nullable($data['statement'] ?? null),
                'explanation' => $this->nullable($data['explanation'] ?? null),
                'sort_order' => $index,
            ])->save();
            $seen[$priority->public_id] = true;
        }
        foreach ($existing as $publicId => $priority) {
            if (! isset($seen[$publicId])) { $priority->delete(); }
        }
    }

    private function syncDepartments(AnnualManagementPolicy $policy, array $submitted): void
    {
        $existing = AnnualManagementPolicyDepartment::query()->where('annual_management_policy_id', $policy->id)->lockForUpdate()->get()->keyBy('public_id');
        $existingByGroup = $existing->keyBy('organization_group_id');
        $seen = [];
        $usedGroups = [];
        foreach (array_values($submitted) as $index => $data) {
            $publicId = $this->nullable($data['public_id'] ?? null);
            $department = $publicId ? $existing->get($publicId) : null;
            if ($publicId && (! $department || isset($seen[$publicId]))) {
                throw ValidationException::withMessages(["departments.$index.public_id" => '部署方針IDが正しくありません。']);
            }
            $group = OrganizationGroup::query()->where('organization_id', $policy->organization_id)
                ->where('public_id', (string) ($data['group_public_id'] ?? ''))->first();
            $department ??= $group ? $existingByGroup->get($group->id) : null;
            if (! $group || (! $department && $group->archived_at !== null) || isset($usedGroups[$group->id])) {
                throw ValidationException::withMessages(["departments.$index.group_public_id" => '同じ会社の利用中の部署を重複なく指定してください。']);
            }
            $department ??= new AnnualManagementPolicyDepartment(['annual_management_policy_id' => $policy->id]);
            $department->fill([
                'organization_group_id' => $group->id,
                'introduction' => $this->nullable($data['introduction'] ?? null),
                'sort_order' => $index,
            ])->save();
            $this->syncDepartmentStatements($department, $data['statements'] ?? []);
            $seen[$department->public_id] = true;
            $usedGroups[$group->id] = true;
        }
        foreach ($existing as $publicId => $department) {
            if (! isset($seen[$publicId])) { $department->delete(); }
        }
    }

    private function syncDepartmentStatements(AnnualManagementPolicyDepartment $department, array $submitted): void
    {
        $existing = AnnualManagementPolicyDepartmentStatement::query()
            ->where('annual_management_policy_department_id', $department->id)
            ->lockForUpdate()->get()->keyBy('public_id');
        $seen = [];
        foreach (array_values($submitted) as $index => $data) {
            $publicId = $this->nullable($data['public_id'] ?? null);
            $statement = $publicId ? $existing->get($publicId) : null;
            if ($publicId && (! $statement || isset($seen[$publicId]))) {
                throw ValidationException::withMessages(["statements.$index.public_id" => '部署方針Statement IDが正しくありません。']);
            }
            $statement ??= new AnnualManagementPolicyDepartmentStatement([
                'annual_management_policy_department_id' => $department->id,
            ]);
            $statement->fill([
                'statement' => $this->nullable($data['statement'] ?? null),
                'explanation' => $this->nullable($data['explanation'] ?? null),
                'sort_order' => $index,
            ])->save();
            $seen[$statement->public_id] = true;
        }
        foreach ($existing as $publicId => $statement) {
            if (! isset($seen[$publicId])) { $statement->delete(); }
        }
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

    private function fromOperation(AnnualManagementPolicyOperation $operation): AnnualManagementPolicy
    {
        return $this->load(AnnualManagementPolicy::query()->findOrFail($operation->annual_management_policy_id));
    }

    private function load(AnnualManagementPolicy $policy): AnnualManagementPolicy
    {
        return $policy->fresh([
            'organization', 'period', 'currentApprovedRevision', 'themes.priorities',
            'departments.group', 'departments.statements', 'relations',
        ]);
    }

    private function hash(string $operation, array $payload): string
    {
        return hash('sha256', json_encode([$operation, $payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
