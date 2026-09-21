<?php

namespace App\Services\BusinessDomain;

use App\Models\BusinessDomain;
use App\Models\BusinessDomainItem;
use App\Models\BusinessDomainItemAttribute;
use App\Models\BusinessDomainOperation;
use App\Models\BusinessDomainRevision;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\User;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessDomainWriter
{
    private const DOMAIN_FIELDS = [
        'name', 'description', 'what_summary', 'who_summary', 'value_proposition',
        'geographic_scope_summary', 'market_position_summary', 'self_recognized_strengths',
        'direction', 'direction_memo',
    ];

    public function __construct(
        private readonly BusinessDomainAccess $access,
        private readonly BusinessDomainSnapshot $snapshots,
        private readonly OrganizationAudit $audit,
    ) {}

    public function create(User $actor, Organization $organization, array $input, string $requestId): BusinessDomain
    {
        return DB::transaction(function () use ($actor, $organization, $input, $requestId): BusinessDomain {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeEdit($actor, $organization, true);
            $hash = $this->payloadHash('create', $input);
            if ($existing = $this->completedOperation($organization, $actor, $requestId, 'create', $hash)) {
                return $this->domainFromOperation($existing, $organization, $actor);
            }

            $operation = BusinessDomainOperation::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'operation' => 'create',
            ]);
            $domain = BusinessDomain::create(array_merge(
                Arr::only($input, self::DOMAIN_FIELDS),
                [
                    'organization_id' => $organization->id,
                    'status' => BusinessDomain::STATUS_ACTIVE,
                    'display_order' => ((int) BusinessDomain::query()
                        ->where('organization_id', $organization->id)
                        ->max('display_order')) + 10,
                    'version' => 1,
                    'created_by_user_id' => $actor->id,
                    'updated_by_user_id' => $actor->id,
                ],
            ));
            $this->syncItems($domain, $input['items'] ?? [], $actor);
            $this->checkpoint('after_current_values');
            $revision = $this->recordRevision($domain, $actor, $operation, null);
            $this->checkpoint('after_revision');
            $this->completeOperation($operation, $domain, $revision);
            $this->checkpoint('after_operation');
            $this->recordAudit($organization, $actor, $domain, $operation, null, 1, null, BusinessDomain::STATUS_ACTIVE);
            $this->checkpoint('after_audit');

            return $domain->fresh(['items.attributes', 'revisions']);
        }, 5);
    }

    public function update(
        User $actor,
        Organization $organization,
        BusinessDomain $domain,
        array $input,
        int $expectedVersion,
        string $reason,
        string $requestId,
    ): BusinessDomain {
        return $this->mutate(
            $actor,
            $organization,
            $domain,
            'update',
            $requestId,
            ['input' => $input, 'expected_version' => $expectedVersion, 'reason' => $reason],
            function (BusinessDomain $locked) use ($actor, $input): void {
                $locked->fill(Arr::only($input, self::DOMAIN_FIELDS));
                $locked->updated_by_user_id = $actor->id;
                $locked->version++;
                $locked->save();
                if (array_key_exists('items', $input)) {
                    $this->syncItems($locked, $input['items'] ?? [], $actor);
                }
            },
            $expectedVersion,
            $reason,
            BusinessDomain::STATUS_ACTIVE,
        );
    }

    public function archive(
        User $actor,
        Organization $organization,
        BusinessDomain $domain,
        int $expectedVersion,
        string $reason,
        string $requestId,
    ): BusinessDomain {
        return $this->mutate(
            $actor,
            $organization,
            $domain,
            'archive',
            $requestId,
            ['expected_version' => $expectedVersion, 'reason' => $reason],
            function (BusinessDomain $locked) use ($actor): void {
                $locked->status = BusinessDomain::STATUS_ARCHIVED;
                $locked->archived_at = now();
                $locked->updated_by_user_id = $actor->id;
                $locked->version++;
                $locked->save();
            },
            $expectedVersion,
            $reason,
            BusinessDomain::STATUS_ACTIVE,
        );
    }

    public function reopen(
        User $actor,
        Organization $organization,
        BusinessDomain $domain,
        int $expectedVersion,
        string $reason,
        string $requestId,
    ): BusinessDomain {
        return $this->mutate(
            $actor,
            $organization,
            $domain,
            'reopen',
            $requestId,
            ['expected_version' => $expectedVersion, 'reason' => $reason],
            function (BusinessDomain $locked) use ($actor): void {
                $locked->status = BusinessDomain::STATUS_ACTIVE;
                $locked->archived_at = null;
                $locked->updated_by_user_id = $actor->id;
                $locked->version++;
                $locked->save();
            },
            $expectedVersion,
            $reason,
            BusinessDomain::STATUS_ARCHIVED,
        );
    }

    public function move(
        User $actor,
        Organization $organization,
        BusinessDomain $domain,
        string $direction,
        string $requestId,
    ): BusinessDomain {
        if (! in_array($direction, ['up', 'down'], true)) {
            throw ValidationException::withMessages(['direction' => '表示順の操作が正しくありません。']);
        }

        return DB::transaction(function () use ($actor, $organization, $domain, $direction, $requestId): BusinessDomain {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeEdit($actor, $organization, true);
            $payload = ['domain_public_id' => $domain->public_id, 'direction' => $direction];
            $hash = $this->payloadHash('reorder', $payload);
            if ($existing = $this->completedOperation($organization, $actor, $requestId, 'reorder', $hash)) {
                return $this->domainFromOperation($existing, $organization, $actor);
            }

            $locked = BusinessDomain::query()->lockForUpdate()->find($domain->id);
            if (! $locked || $locked->organization_id !== $organization->id) {
                throw (new ModelNotFoundException)->setModel(BusinessDomain::class);
            }

            $ordered = BusinessDomain::query()
                ->where('organization_id', $organization->id)
                ->where('status', $locked->status)
                ->orderBy('display_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->values();
            $currentIndex = $ordered->search(fn (BusinessDomain $candidate): bool => $candidate->id === $locked->id);
            $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
            if ($currentIndex === false || ! $ordered->has($targetIndex)) {
                throw ValidationException::withMessages(['direction' => 'これ以上移動できません。']);
            }

            $beforeOrder = $this->orderMetadata($ordered->all());
            $domains = $ordered->all();
            [$domains[$currentIndex], $domains[$targetIndex]] = [$domains[$targetIndex], $domains[$currentIndex]];
            $operation = BusinessDomainOperation::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'operation' => 'reorder',
                'business_domain_id' => $locked->id,
            ]);

            foreach ($domains as $index => $orderedDomain) {
                DB::table('business_domains')
                    ->where('id', $orderedDomain->id)
                    ->update(['display_order' => ($index + 1) * 10]);
                $orderedDomain->display_order = ($index + 1) * 10;
            }
            $this->checkpoint('after_current_values');
            $afterOrder = $this->orderMetadata($domains);
            $operation->update([
                'result_metadata' => [
                    'domain_public_id' => $locked->public_id,
                    'direction' => $direction,
                    'before_order' => $beforeOrder,
                    'after_order' => $afterOrder,
                ],
                'completed_at' => now(),
            ]);
            $this->checkpoint('after_operation');
            $this->audit->record(
                $organization,
                $actor,
                'business_domain.reorder',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: ['display_order' => $beforeOrder],
                after: ['display_order' => $afterOrder],
                metadata: [
                    'domain_public_id' => $locked->public_id,
                    'operation_id' => $operation->id,
                    'request_id' => $operation->request_id,
                    'direction' => $direction,
                ],
            );
            $this->checkpoint('after_audit');

            return $locked->fresh(['items.attributes', 'revisions']);
        }, 5);
    }

    private function mutate(
        User $actor,
        Organization $organization,
        BusinessDomain $domain,
        string $operationName,
        string $requestId,
        array $payload,
        callable $mutation,
        int $expectedVersion,
        string $reason,
        string $requiredStatus,
    ): BusinessDomain {
        return DB::transaction(function () use (
            $actor, $organization, $domain, $operationName, $requestId, $payload,
            $mutation, $expectedVersion, $reason, $requiredStatus,
        ): BusinessDomain {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeEdit($actor, $organization, true);
            $hash = $this->payloadHash($operationName, $payload);
            if ($existing = $this->completedOperation($organization, $actor, $requestId, $operationName, $hash)) {
                return $this->domainFromOperation($existing, $organization, $actor);
            }

            $locked = BusinessDomain::query()->lockForUpdate()->find($domain->id);
            if (! $locked || $locked->organization_id !== $organization->id) {
                throw (new ModelNotFoundException)->setModel(BusinessDomain::class);
            }
            if ($locked->status !== $requiredStatus) {
                throw ValidationException::withMessages(['status' => '現在の状態ではこの操作を実行できません。']);
            }
            if ($locked->version !== $expectedVersion) {
                throw ValidationException::withMessages(['expected_version' => '別の変更が保存されています。再読み込みしてください。']);
            }

            $beforeVersion = $locked->version;
            $beforeStatus = $locked->status;
            $operation = BusinessDomainOperation::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'operation' => $operationName,
                'business_domain_id' => $locked->id,
            ]);
            $mutation($locked);
            $this->checkpoint('after_current_values');
            $revision = $this->recordRevision($locked, $actor, $operation, $reason);
            $this->checkpoint('after_revision');
            $this->completeOperation($operation, $locked, $revision);
            $this->checkpoint('after_operation');
            $this->recordAudit(
                $organization,
                $actor,
                $locked,
                $operation,
                $beforeVersion,
                $locked->version,
                $beforeStatus,
                $locked->status,
            );
            $this->checkpoint('after_audit');

            return $locked->fresh(['items.attributes', 'revisions']);
        }, 5);
    }

    private function syncItems(BusinessDomain $domain, array $submittedItems, User $actor): void
    {
        $existing = BusinessDomainItem::query()
            ->where('business_domain_id', $domain->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('public_id');
        $seen = [];

        foreach (array_values($submittedItems) as $itemIndex => $data) {
            $publicId = $data['public_id'] ?? null;
            if ($publicId && isset($seen[$publicId])) {
                throw ValidationException::withMessages(["items.$itemIndex.public_id" => '同じ明細IDが重複しています。']);
            }
            $item = $publicId ? $existing->get($publicId) : null;
            if ($publicId && ! $item) {
                throw ValidationException::withMessages(["items.$itemIndex.public_id" => 'この事業領域の明細ではありません。']);
            }
            $item ??= new BusinessDomainItem([
                'business_domain_id' => $domain->id,
                'created_by_user_id' => $actor->id,
            ]);
            $item->fill([
                'kind' => $data['kind'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => BusinessDomainItem::STATUS_ACTIVE,
                'sort_order' => $itemIndex,
                'updated_by_user_id' => $actor->id,
            ]);
            $item->save();
            $seen[$item->public_id] = true;
            if (array_key_exists('attributes', $data)) {
                $this->syncAttributes($item, $data['attributes'] ?? [], $itemIndex);
            }
        }

        foreach ($existing as $publicId => $item) {
            if (! isset($seen[$publicId]) && $item->status !== BusinessDomainItem::STATUS_ARCHIVED) {
                $item->update(['status' => BusinessDomainItem::STATUS_ARCHIVED, 'updated_by_user_id' => $actor->id]);
            }
        }
    }

    private function syncAttributes(BusinessDomainItem $item, array $submitted, int $itemIndex): void
    {
        $existing = BusinessDomainItemAttribute::query()
            ->where('business_domain_item_id', $item->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('public_id');
        $seen = [];

        foreach (array_values($submitted) as $attributeIndex => $data) {
            $publicId = $data['public_id'] ?? null;
            if ($publicId && isset($seen[$publicId])) {
                throw ValidationException::withMessages([
                    "items.$itemIndex.attributes.$attributeIndex.public_id" => '同じ属性IDが重複しています。',
                ]);
            }
            $attribute = $publicId ? $existing->get($publicId) : null;
            if ($publicId && ! $attribute) {
                throw ValidationException::withMessages([
                    "items.$itemIndex.attributes.$attributeIndex.public_id" => 'この明細の属性ではありません。',
                ]);
            }
            $attribute ??= new BusinessDomainItemAttribute(['business_domain_item_id' => $item->id]);
            $attribute->fill([
                'axis' => $data['axis'],
                'label' => $data['label'],
                'value_text' => $data['value_text'],
                'status' => BusinessDomainItemAttribute::STATUS_ACTIVE,
                'sort_order' => $attributeIndex,
            ]);
            $attribute->save();
            $seen[$attribute->public_id] = true;
        }

        foreach ($existing as $publicId => $attribute) {
            if (! isset($seen[$publicId]) && $attribute->status !== BusinessDomainItemAttribute::STATUS_ARCHIVED) {
                $attribute->update(['status' => BusinessDomainItemAttribute::STATUS_ARCHIVED]);
            }
        }
    }

    private function recordRevision(
        BusinessDomain $domain,
        User $actor,
        BusinessDomainOperation $operation,
        ?string $reason,
    ): BusinessDomainRevision {
        $domain->unsetRelation('items');

        return BusinessDomainRevision::create([
            'business_domain_id' => $domain->id,
            'revision_no' => $domain->version,
            'snapshot_schema_version' => BusinessDomainSnapshot::SCHEMA_VERSION,
            'snapshot' => $this->snapshots->make($domain),
            'actor_user_id' => $actor->id,
            'business_domain_operation_id' => $operation->id,
            'change_reason' => $reason,
            'changed_at' => now(),
        ]);
    }

    /**
     * @param  array<int, BusinessDomain>  $domains
     */
    private function orderMetadata(array $domains): array
    {
        return array_values(array_map(
            fn (BusinessDomain $orderedDomain): array => [
                'domain_public_id' => $orderedDomain->public_id,
                'display_order' => (int) $orderedDomain->display_order,
            ],
            $domains,
        ));
    }

    private function completeOperation(
        BusinessDomainOperation $operation,
        BusinessDomain $domain,
        BusinessDomainRevision $revision,
    ): void {
        $operation->update([
            'business_domain_id' => $domain->id,
            'result_revision_no' => $revision->revision_no,
            'result_metadata' => ['domain_public_id' => $domain->public_id],
            'completed_at' => now(),
        ]);
    }

    private function completedOperation(
        Organization $organization,
        User $actor,
        string $requestId,
        string $operation,
        string $hash,
    ): ?BusinessDomainOperation {
        $existing = BusinessDomainOperation::query()
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
        if (! $existing->completed_at || ! $existing->business_domain_id) {
            throw ValidationException::withMessages(['request_id' => '操作の完了状態を確認できません。']);
        }

        return $existing;
    }

    private function domainFromOperation(
        BusinessDomainOperation $operation,
        Organization $organization,
        User $actor,
    ): BusinessDomain {
        $this->access->authorizeEdit($actor, $organization, true);
        $domain = BusinessDomain::query()->with(['items.attributes', 'revisions'])->find($operation->business_domain_id);
        if (! $domain || $domain->organization_id !== $organization->id) {
            throw (new ModelNotFoundException)->setModel(BusinessDomain::class);
        }

        return $domain;
    }

    private function recordAudit(
        Organization $organization,
        User $actor,
        BusinessDomain $domain,
        BusinessDomainOperation $operation,
        ?int $beforeVersion,
        int $afterVersion,
        ?string $beforeStatus,
        string $afterStatus,
    ): void {
        $this->audit->record(
            $organization,
            $actor,
            'business_domain.'.$operation->operation,
            OrganizationAuditEvent::OUTCOME_SUCCESS,
            before: $beforeVersion === null ? null : ['version' => $beforeVersion, 'status' => $beforeStatus],
            after: ['version' => $afterVersion, 'status' => $afterStatus],
            metadata: [
                'domain_public_id' => $domain->public_id,
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

    protected function checkpoint(string $name): void
    {
        // Test seam for transaction rollback evidence.
    }
}
