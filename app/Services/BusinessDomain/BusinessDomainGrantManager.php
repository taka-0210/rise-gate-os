<?php

namespace App\Services\BusinessDomain;

use App\Models\BusinessDomainEditorGrant;
use App\Models\BusinessDomainOperation;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessDomainGrantManager
{
    public function __construct(
        private readonly BusinessDomainAccess $access,
        private readonly OrganizationAudit $audit,
    ) {}

    public function grant(
        User $actor,
        Organization $organization,
        OrganizationUser $target,
        string $requestId,
    ): BusinessDomainEditorGrant {
        return $this->change($actor, $organization, $target, $requestId, 'grant');
    }

    public function revoke(
        User $actor,
        Organization $organization,
        OrganizationUser $target,
        string $requestId,
    ): BusinessDomainEditorGrant {
        return $this->change($actor, $organization, $target, $requestId, 'revoke');
    }

    private function change(
        User $actor,
        Organization $organization,
        OrganizationUser $target,
        string $requestId,
        string $action,
    ): BusinessDomainEditorGrant {
        return DB::transaction(function () use ($actor, $organization, $target, $requestId, $action): BusinessDomainEditorGrant {
            $organization = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $this->access->authorizeOwner($actor, $organization, true);
            $target = OrganizationUser::query()->with('user')->lockForUpdate()->find($target->id);
            if (! $target || $target->organization_id !== $organization->id) {
                throw (new ModelNotFoundException)->setModel(OrganizationUser::class);
            }

            $hash = hash('sha256', json_encode([
                'operation' => $action,
                'organization_user_id' => $target->id,
            ], JSON_THROW_ON_ERROR));
            $existingOperation = BusinessDomainOperation::query()
                ->where('organization_id', $organization->id)
                ->where('actor_user_id', $actor->id)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($existingOperation) {
                if ($existingOperation->operation !== $action || ! hash_equals($existingOperation->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_id' => '同じ操作IDを異なる内容には使用できません。']);
                }
                $grant = BusinessDomainEditorGrant::query()
                    ->where('organization_id', $organization->id)
                    ->where('organization_user_id', $target->id)
                    ->first();
                if (! $existingOperation->completed_at || ! $grant) {
                    throw ValidationException::withMessages(['request_id' => '操作の完了状態を確認できません。']);
                }

                return $grant;
            }

            if ($action === 'grant') {
                if ($target->membership_status !== OrganizationUser::STATUS_ACTIVE
                    || ! $target->user?->is_active
                    || ! in_array($target->organization_role, [
                        OrganizationUser::ORGANIZATION_ROLE_ADMIN,
                        OrganizationUser::ORGANIZATION_ROLE_MEMBER,
                    ], true)) {
                    throw ValidationException::withMessages(['membership' => '有効なAdminまたはMemberだけを編集担当に指定できます。']);
                }
            }

            $operation = BusinessDomainOperation::create([
                'organization_id' => $organization->id,
                'actor_user_id' => $actor->id,
                'request_id' => $requestId,
                'payload_hash' => $hash,
                'operation' => $action,
            ]);
            $grant = BusinessDomainEditorGrant::query()
                ->where('organization_id', $organization->id)
                ->where('organization_user_id', $target->id)
                ->lockForUpdate()
                ->first();

            if ($action === 'grant') {
                $grant ??= new BusinessDomainEditorGrant([
                    'organization_id' => $organization->id,
                    'organization_user_id' => $target->id,
                ]);
                $grant->fill([
                    'granted_by_user_id' => $actor->id,
                    'granted_at' => now(),
                    'revoked_by_user_id' => null,
                    'revoked_at' => null,
                ])->save();
            } else {
                if (! $grant) {
                    throw ValidationException::withMessages(['membership' => '解除できる編集担当がありません。']);
                }
                if ($grant->revoked_at === null) {
                    $grant->update(['revoked_by_user_id' => $actor->id, 'revoked_at' => now()]);
                }
            }

            $operation->update([
                'result_metadata' => [
                    'organization_user_id' => $target->id,
                    'grant_id' => $grant->id,
                    'active' => $grant->revoked_at === null,
                ],
                'completed_at' => now(),
            ]);
            $this->audit->record(
                $organization,
                $actor,
                'business_domain.editor_'.$action,
                OrganizationAuditEvent::OUTCOME_SUCCESS,
                subject: $target->user,
                before: ['editor_grant_active' => $action === 'revoke'],
                after: ['editor_grant_active' => $action === 'grant'],
                metadata: [
                    'organization_user_id' => $target->id,
                    'operation_id' => $operation->id,
                    'request_id' => $requestId,
                ],
            );

            return $grant->fresh();
        }, 5);
    }
}
