<?php

namespace App\Services\ManagementDesign;

use App\Models\ManagementDesignAccessSetting;
use App\Models\ManagementDesignGrant;
use App\Models\ManagementDesignItem;
use App\Models\ManagementDesignOperation;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagementDesignPermissionManager
{
    public function __construct(
        private readonly ManagementDesignAccess $access,
        private readonly OrganizationAudit $audit,
    ) {}

    public function update(
        User $actor,
        Organization $organization,
        string $type,
        string $viewScope,
        array $submittedGrants,
        string $requestId,
    ): ManagementDesignAccessSetting {
        return DB::transaction(function () use (
            $actor, $organization, $type, $viewScope, $submittedGrants, $requestId,
        ): ManagementDesignAccessSetting {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeManage($actor, $organization, true);
            $this->validateContract($type, $viewScope, $submittedGrants);
            $payload = ['type' => $type, 'view_scope' => $viewScope, 'grants' => $submittedGrants];
            $hash = $this->payloadHash('permissions_update', $payload);
            if ($existing = $this->completedOperation($organization, $actor, $requestId, $hash)) {
                return ManagementDesignAccessSetting::query()
                    ->where('organization_id', $organization->id)
                    ->where('item_type', $type)
                    ->firstOrFail();
            }

            $membershipIds = array_map('intval', array_keys($submittedGrants));
            $memberships = OrganizationUser::query()
                ->where('organization_id', $organization->id)
                ->whereIn('id', $membershipIds)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($memberships->count() !== count(array_unique($membershipIds))) {
                throw ValidationException::withMessages(['grants' => '現在利用中の所属だけを指定できます。']);
            }

            $setting = ManagementDesignAccessSetting::query()
                ->where('organization_id', $organization->id)
                ->where('item_type', $type)
                ->lockForUpdate()
                ->first();
            $beforeScope = $setting?->view_scope ?? ManagementDesignAccessSetting::VIEW_SCOPE_EXPLICIT;
            $beforeCounts = $this->grantCounts($organization, $type);
            $operation = ManagementDesignOperation::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'operation' => 'permissions_update',
            ]);

            $setting ??= new ManagementDesignAccessSetting([
                'organization_id' => $organization->id,
                'item_type' => $type,
            ]);
            $setting->fill(['view_scope' => $viewScope, 'updated_by_user_id' => $actor->id])->save();

            $existingGrants = ManagementDesignGrant::query()
                ->where('organization_id', $organization->id)
                ->where('item_type', $type)
                ->lockForUpdate()
                ->get()
                ->keyBy('organization_user_id');
            foreach ($existingGrants as $grant) {
                $grant->update(['can_view' => false, 'can_edit' => false, 'updated_by_user_id' => $actor->id]);
            }
            foreach ($submittedGrants as $membershipId => $permissions) {
                $grant = $existingGrants->get((int) $membershipId) ?? new ManagementDesignGrant([
                    'organization_id' => $organization->id,
                    'item_type' => $type,
                    'organization_user_id' => (int) $membershipId,
                ]);
                $grant->fill([
                    'can_view' => (bool) ($permissions['can_view'] ?? false),
                    'can_edit' => (bool) ($permissions['can_edit'] ?? false),
                    'updated_by_user_id' => $actor->id,
                ])->save();
            }

            $afterCounts = $this->grantCounts($organization, $type);
            $operation->update([
                'result_metadata' => ['item_type' => $type, 'view_scope' => $viewScope],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $organization,
                $actor,
                'management_design.permissions_update',
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                before: ['view_scope' => $beforeScope, 'grant_counts' => $beforeCounts],
                after: ['view_scope' => $viewScope, 'grant_counts' => $afterCounts],
                metadata: ['item_type' => $type, 'operation_id' => $operation->id, 'request_id' => $requestId],
            );

            return $setting->fresh();
        }, 5);
    }

    private function validateContract(string $type, string $viewScope, array $grants): void
    {
        if (! in_array($type, ManagementDesignItem::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Management Designの種類が正しくありません。']);
        }
        if (! in_array($viewScope, ManagementDesignAccessSetting::VIEW_SCOPES, true)) {
            throw ValidationException::withMessages(['view_scope' => '共有範囲が正しくありません。']);
        }
        foreach ($grants as $membershipId => $permissions) {
            if (! ctype_digit((string) $membershipId) || ! is_array($permissions)) {
                throw ValidationException::withMessages(['grants' => '権限指定が正しくありません。']);
            }
            if ($viewScope === ManagementDesignAccessSetting::VIEW_SCOPE_EXPLICIT
                && (bool) ($permissions['can_edit'] ?? false)
                && ! (bool) ($permissions['can_view'] ?? false)) {
                throw ValidationException::withMessages(['grants' => '編集担当には閲覧権限も明示してください。']);
            }
        }
    }

    private function grantCounts(Organization $organization, string $type): array
    {
        $query = ManagementDesignGrant::query()
            ->where('organization_id', $organization->id)
            ->where('item_type', $type);

        return [
            'viewers' => (clone $query)->where('can_view', true)->count(),
            'editors' => (clone $query)->where('can_edit', true)->count(),
        ];
    }

    private function completedOperation(
        Organization $organization,
        User $actor,
        string $requestId,
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
        if ($existing->operation !== 'permissions_update' || ! hash_equals($existing->payload_hash, $hash)) {
            throw ValidationException::withMessages(['request_id' => '同じ操作IDを異なる内容には使用できません。']);
        }
        if (! $existing->completed_at) {
            throw ValidationException::withMessages(['request_id' => '操作の完了状態を確認できません。']);
        }

        return $existing;
    }

    private function payloadHash(string $operation, array $payload): string
    {
        $payload['grants'] = collect($payload['grants'])->sortKeys()->all();

        return hash('sha256', json_encode(
            ['operation' => $operation, 'payload' => $payload],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));
    }
}
