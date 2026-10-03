<?php

namespace App\Services\ManagementDesign;

use App\Models\ManagementDesignItem;
use App\Models\ManagementDesignOperation;
use App\Models\ManagementDesignRevision;
use App\Models\ManagementDesignSection;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\User;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagementDesignWriter
{
    public function __construct(
        private readonly ManagementDesignAccess $access,
        private readonly ManagementDesignSnapshot $snapshots,
        private readonly OrganizationAudit $audit,
    ) {}

    public function saveOfficial(
        User $actor,
        Organization $organization,
        string $type,
        array $input,
        int $expectedVersion,
        ?string $reason,
        string $requestId,
    ): ManagementDesignItem {
        return DB::transaction(function () use (
            $actor, $organization, $type, $input, $expectedVersion, $reason, $requestId,
        ): ManagementDesignItem {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeEdit($actor, $organization, $type, true);
            $payload = [
                'type' => $type,
                'input' => $input,
                'expected_version' => $expectedVersion,
                'reason' => $this->nullableText($reason),
            ];
            $hash = $this->payloadHash('official_save', $payload);
            if ($existing = $this->completedOperation($organization, $actor, $requestId, 'official_save', $hash)) {
                return $this->itemFromOperation($existing, $organization, $type);
            }

            $item = ManagementDesignItem::query()
                ->where('organization_id', $organization->id)
                ->where('type', $type)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                if ($expectedVersion !== 0) {
                    throw ValidationException::withMessages(['expected_version' => '正本の状態が変更されています。再読み込みしてください。']);
                }
                $item = ManagementDesignItem::create([
                    'organization_id' => $organization->id,
                    'type' => $type,
                    'status' => ManagementDesignItem::STATUS_ACTIVE,
                    'version' => 0,
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                ]);
            } elseif ($item->status !== ManagementDesignItem::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['status' => '保管中の正本は、再開してから編集してください。']);
            }

            if ((int) $item->version !== $expectedVersion) {
                throw ValidationException::withMessages(['expected_version' => '別の変更が正本として保存されています。再読み込みしてください。']);
            }

            $operation = ManagementDesignOperation::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'operation' => 'official_save',
                'management_design_item_id' => $item->id,
            ]);
            $beforeVersion = (int) $item->version;
            $item->fill([
                'statement' => $this->nullableText($input['statement'] ?? null),
                'statement_explanation' => $this->nullableText($input['statement_explanation'] ?? null),
                'horizon' => $type === ManagementDesignItem::TYPE_VISION
                    ? $this->nullableText($input['horizon'] ?? null)
                    : null,
                'updated_by_user_id' => $actor->id,
                'version' => $beforeVersion + 1,
            ])->save();
            $this->syncSections($item, $input['sections'] ?? [], $actor);
            $this->checkpoint('after_current_values');
            $revision = $this->recordRevision($item, $actor, $operation, $reason);
            $item->update(['current_revision_id' => $revision->id]);
            $this->checkpoint('after_revision');
            $this->completeOperation($operation, $item, $revision);
            $this->checkpoint('after_operation');
            $this->recordAudit($organization, $actor, $item, $operation, $beforeVersion, $item->version);
            $this->checkpoint('after_audit');

            return $item->fresh(['sections', 'currentRevision', 'revisions']);
        }, 5);
    }

    public function archive(
        User $actor,
        Organization $organization,
        string $type,
        int $expectedVersion,
        ?string $reason,
        string $requestId,
    ): ManagementDesignItem {
        return $this->changeStatus(
            $actor,
            $organization,
            $type,
            $expectedVersion,
            $reason,
            $requestId,
            'archive',
            ManagementDesignItem::STATUS_ACTIVE,
            ManagementDesignItem::STATUS_ARCHIVED,
        );
    }

    public function reopen(
        User $actor,
        Organization $organization,
        string $type,
        int $expectedVersion,
        ?string $reason,
        string $requestId,
    ): ManagementDesignItem {
        return $this->changeStatus(
            $actor,
            $organization,
            $type,
            $expectedVersion,
            $reason,
            $requestId,
            'reopen',
            ManagementDesignItem::STATUS_ARCHIVED,
            ManagementDesignItem::STATUS_ACTIVE,
        );
    }

    private function changeStatus(
        User $actor,
        Organization $organization,
        string $type,
        int $expectedVersion,
        ?string $reason,
        string $requestId,
        string $operationName,
        string $fromStatus,
        string $toStatus,
    ): ManagementDesignItem {
        return DB::transaction(function () use (
            $actor, $organization, $type, $expectedVersion, $reason, $requestId,
            $operationName, $fromStatus, $toStatus,
        ): ManagementDesignItem {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeEdit($actor, $organization, $type, true);
            $payload = [
                'type' => $type,
                'expected_version' => $expectedVersion,
                'reason' => $this->nullableText($reason),
            ];
            $hash = $this->payloadHash($operationName, $payload);
            if ($existing = $this->completedOperation($organization, $actor, $requestId, $operationName, $hash)) {
                return $this->itemFromOperation($existing, $organization, $type);
            }

            $item = ManagementDesignItem::query()
                ->where('organization_id', $organization->id)
                ->where('type', $type)
                ->lockForUpdate()
                ->first();
            if (! $item) {
                throw (new ModelNotFoundException)->setModel(ManagementDesignItem::class);
            }
            if ($item->status !== $fromStatus) {
                throw ValidationException::withMessages(['status' => '現在の状態ではこの操作を実行できません。']);
            }
            if ((int) $item->version !== $expectedVersion) {
                throw ValidationException::withMessages(['expected_version' => '別の変更が保存されています。再読み込みしてください。']);
            }

            $operation = ManagementDesignOperation::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'operation' => $operationName,
                'management_design_item_id' => $item->id,
            ]);
            $beforeVersion = (int) $item->version;
            $item->fill([
                'status' => $toStatus,
                'version' => $beforeVersion + 1,
                'updated_by_user_id' => $actor->id,
                'archived_at' => $toStatus === ManagementDesignItem::STATUS_ARCHIVED ? now() : null,
            ])->save();
            $this->checkpoint('after_current_values');
            $revision = $this->recordRevision($item, $actor, $operation, $reason);
            $item->update(['current_revision_id' => $revision->id]);
            $this->checkpoint('after_revision');
            $this->completeOperation($operation, $item, $revision);
            $this->checkpoint('after_operation');
            $this->recordAudit($organization, $actor, $item, $operation, $beforeVersion, $item->version);
            $this->checkpoint('after_audit');

            return $item->fresh(['sections', 'currentRevision', 'revisions']);
        }, 5);
    }

    private function syncSections(ManagementDesignItem $item, array $submitted, User $actor): void
    {
        $existing = ManagementDesignSection::query()
            ->where('management_design_item_id', $item->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('public_id');
        $seen = [];

        foreach (array_values($submitted) as $index => $data) {
            $publicId = $data['public_id'] ?? null;
            if ($publicId && isset($seen[$publicId])) {
                throw ValidationException::withMessages(["sections.$index.public_id" => '同じSection IDが重複しています。']);
            }
            $section = $publicId ? $existing->get($publicId) : null;
            if ($publicId && ! $section) {
                throw ValidationException::withMessages(["sections.$index.public_id" => 'この正本のSectionではありません。']);
            }
            $section ??= new ManagementDesignSection([
                'management_design_item_id' => $item->id,
                'created_by_user_id' => $actor->id,
            ]);
            $section->fill([
                'title' => $data['title'],
                'body' => $data['body'],
                'explanation' => $this->nullableText($data['explanation'] ?? null),
                'horizon' => $item->type === ManagementDesignItem::TYPE_VISION
                    ? $this->nullableText($data['horizon'] ?? null)
                    : null,
                'sort_order' => $index,
                'status' => ManagementDesignSection::STATUS_ACTIVE,
                'updated_by_user_id' => $actor->id,
            ])->save();
            $seen[$section->public_id] = true;
        }

        foreach ($existing as $publicId => $section) {
            if (! isset($seen[$publicId]) && $section->status !== ManagementDesignSection::STATUS_ARCHIVED) {
                $section->update([
                    'status' => ManagementDesignSection::STATUS_ARCHIVED,
                    'updated_by_user_id' => $actor->id,
                ]);
            }
        }
    }

    private function recordRevision(
        ManagementDesignItem $item,
        User $actor,
        ManagementDesignOperation $operation,
        ?string $reason,
    ): ManagementDesignRevision {
        $item->unsetRelation('sections');

        return ManagementDesignRevision::create([
            'management_design_item_id' => $item->id,
            'revision_no' => $item->version,
            'snapshot_schema_version' => ManagementDesignSnapshot::SCHEMA_VERSION,
            'status' => $item->status,
            'snapshot' => $this->snapshots->make($item),
            'actor_user_id' => $actor->id,
            'management_design_operation_id' => $operation->id,
            'change_reason' => $this->nullableText($reason),
            'changed_at' => now(),
        ]);
    }

    private function completeOperation(
        ManagementDesignOperation $operation,
        ManagementDesignItem $item,
        ManagementDesignRevision $revision,
    ): void {
        $operation->update([
            'management_design_item_id' => $item->id,
            'result_revision_no' => $revision->revision_no,
            'result_metadata' => ['item_public_id' => $item->public_id, 'item_type' => $item->type],
            'completed_at' => now(),
        ]);
    }

    private function completedOperation(
        Organization $organization,
        User $actor,
        string $requestId,
        string $operation,
        string $hash,
    ): ?ManagementDesignOperation {
        $existing = ManagementDesignOperation::query()
            ->where('organization_id', $organization->id)
            ->where('actor_user_id', $actor->id)
            ->where('request_id', $requestId)
            ->lockForUpdate()
            ->first();
        if (! $existing) {
            return null;
        }
        if ($existing->operation !== $operation || ! hash_equals($existing->payload_hash, $hash)) {
            throw ValidationException::withMessages(['request_id' => '同じ操作IDを異なる内容には使用できません。']);
        }
        if (! $existing->completed_at || ! $existing->management_design_item_id) {
            throw ValidationException::withMessages(['request_id' => '操作の完了状態を確認できません。']);
        }

        return $existing;
    }

    private function itemFromOperation(
        ManagementDesignOperation $operation,
        Organization $organization,
        string $type,
    ): ManagementDesignItem {
        $item = ManagementDesignItem::query()
            ->with(['sections', 'currentRevision', 'revisions'])
            ->find($operation->management_design_item_id);
        if (! $item || $item->organization_id !== $organization->id || $item->type !== $type) {
            throw (new ModelNotFoundException)->setModel(ManagementDesignItem::class);
        }

        return $item;
    }

    private function recordAudit(
        Organization $organization,
        User $actor,
        ManagementDesignItem $item,
        ManagementDesignOperation $operation,
        int $beforeVersion,
        int $afterVersion,
    ): void {
        $this->audit->record(
            $organization,
            $actor,
            'management_design.'.$operation->operation,
            OrganizationAuditEvent::OUTCOME_SUCCESS,
            before: ['version' => $beforeVersion],
            after: ['version' => $afterVersion, 'status' => $item->status],
            metadata: [
                'item_public_id' => $item->public_id,
                'item_type' => $item->type,
                'revision_no' => $operation->result_revision_no ?? $afterVersion,
                'operation_id' => $operation->id,
                'request_id' => $operation->request_id,
            ],
        );
    }

    private function payloadHash(string $operation, array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize(['operation' => $operation, 'payload' => $payload]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected function checkpoint(string $name): void
    {
        // Test seam for transaction rollback evidence.
    }
}
