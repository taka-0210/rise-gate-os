<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedConversation;
use App\Models\AiCommonSharedOperation;
use App\Models\AiCommonSharedParticipant;
use App\Models\AiCommonSharedPurposeRevision;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonSharedConversationWriter
{
    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly AiCommonSharedAccess $access,
    ) {}

    public function create(User $actor, Organization $organization, array $input): AiCommonConversation
    {
        $operationId = $this->operationId($input);
        $name = trim((string) ($input['name'] ?? ''));
        $purpose = trim((string) ($input['purpose'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Nameは1文字以上160文字以内で入力してください。']);
        }
        if ($purpose === '' || mb_strlen($purpose) > 4000) {
            throw ValidationException::withMessages(['purpose' => 'Purposeは1文字以上4000文字以内で入力してください。']);
        }
        $fingerprint = $this->fingerprint(['name' => $name, 'purpose' => $purpose]);
        $this->common->authorizeOrganization($actor, $organization);
        if ($existing = $this->existing($organization, $actor, $operationId, 'create', $fingerprint)) {
            return AiCommonConversation::query()->findOrFail($existing->result_id);
        }

        try {
            return DB::transaction(function () use ($actor, $organization, $operationId, $name, $purpose, $fingerprint): AiCommonConversation {
                $membership = $this->common->authorizeOrganization($actor, $organization, true);
                if ($existing = $this->existing($organization, $actor, $operationId, 'create', $fingerprint)) {
                    return AiCommonConversation::query()->findOrFail($existing->result_id);
                }
                $conversation = AiCommonConversation::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $actor->id,
                    'conversation_kind' => AiCommonConversation::KIND_SHARED,
                    'title' => $name,
                    'status' => AiCommonConversation::STATUS_ACTIVE,
                    'version' => 1,
                ]);
                $shared = AiCommonSharedConversation::query()->create([
                    'organization_id' => $organization->id,
                    'ai_common_conversation_id' => $conversation->id,
                    'owner_user_id' => $actor->id,
                    'participant_version' => 1,
                    'version' => 1,
                ]);
                AiCommonSharedParticipant::query()->create([
                    'ai_common_shared_conversation_id' => $shared->id,
                    'user_id' => $actor->id,
                    'role' => AiCommonSharedParticipant::ROLE_OWNER,
                    'status' => AiCommonSharedParticipant::STATUS_ACTIVE,
                    'invited_by_user_id' => $actor->id,
                    'audience_epoch' => 1,
                    'version' => 1,
                    'accepted_membership_epoch' => $membership->access_epoch,
                    'accepted_credential_generation' => $actor->credential_generation,
                    'invited_at' => now(),
                    'accepted_at' => now(),
                ]);
                $revision = AiCommonSharedPurposeRevision::query()->create([
                    'ai_common_shared_conversation_id' => $shared->id,
                    'revision_no' => 1,
                    'purpose' => $purpose,
                    'purpose_hash' => hash('sha256', $purpose),
                    'created_by_user_id' => $actor->id,
                    'operation_id' => $operationId,
                ]);
                $shared->update(['current_purpose_revision_id' => $revision->id]);
                $this->record($organization, $conversation, $actor, $operationId, 'create', $fingerprint, 'conversation', $conversation->id);

                return $conversation;
            });
        } catch (QueryException $error) {
            if ($existing = $this->existing($organization, $actor, $operationId, 'create', $fingerprint)) {
                return AiCommonConversation::query()->findOrFail($existing->result_id);
            }
            throw $error;
        }
    }

    public function updateIdentity(User $actor, Organization $organization, AiCommonConversation $conversation, array $input): AiCommonConversation
    {
        $operationId = $this->operationId($input);
        $name = trim((string) ($input['name'] ?? ''));
        $purpose = trim((string) ($input['purpose'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160 || $purpose === '' || mb_strlen($purpose) > 4000) {
            throw ValidationException::withMessages(['shared' => 'NameとPurposeを入力範囲内で指定してください。']);
        }
        $fingerprint = $this->fingerprint(['conversation' => $conversation->id, 'name' => $name, 'purpose' => $purpose]);
        $this->access->authorizeOwner($actor, $organization, $conversation, true);
        if ($this->existing($organization, $actor, $operationId, 'update_identity', $fingerprint)) {
            return $conversation->fresh();
        }

        return DB::transaction(function () use ($actor, $organization, $conversation, $operationId, $name, $purpose, $fingerprint): AiCommonConversation {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $owner = $this->access->authorizeOwner($actor, $organization, $locked, true, true);
            $shared = $owner->sharedConversation;
            if ($this->existing($organization, $actor, $operationId, 'update_identity', $fingerprint)) {
                return $locked->fresh();
            }
            $current = $shared->currentPurposeRevision()->firstOrFail();
            if (! hash_equals($current->purpose_hash, hash('sha256', $purpose))) {
                $revision = $shared->purposeRevisions()->create([
                    'revision_no' => $current->revision_no + 1,
                    'purpose' => $purpose,
                    'purpose_hash' => hash('sha256', $purpose),
                    'created_by_user_id' => $actor->id,
                    'operation_id' => $operationId,
                ]);
                $shared->current_purpose_revision_id = $revision->id;
            }
            $shared->version++;
            $shared->save();
            $locked->update(['title' => $name, 'version' => $locked->version + 1]);
            $this->record($organization, $locked, $actor, $operationId, 'update_identity', $fingerprint, 'conversation', $locked->id);

            return $locked->fresh();
        });
    }

    public function invite(User $actor, Organization $organization, AiCommonConversation $conversation, User $invitee, array $input): AiCommonSharedParticipant
    {
        $operationId = $this->operationId($input);
        $fingerprint = $this->fingerprint(['conversation' => $conversation->id, 'invitee' => $invitee->id]);
        $this->access->authorizeOwner($actor, $organization, $conversation, true);
        $this->access->eligibleMembership($invitee, $organization);
        if ($actor->id === $invitee->id) {
            throw ValidationException::withMessages(['invitee_user_id' => 'Owner本人は既にParticipantです。']);
        }
        if ($existing = $this->existing($organization, $actor, $operationId, 'invite', $fingerprint)) {
            return AiCommonSharedParticipant::query()->findOrFail($existing->result_id);
        }

        return DB::transaction(function () use ($actor, $organization, $conversation, $invitee, $operationId, $fingerprint): AiCommonSharedParticipant {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $owner = $this->access->authorizeOwner($actor, $organization, $locked, true, true);
            $shared = $owner->sharedConversation;
            $this->access->eligibleMembership($invitee, $organization, true);
            if ($existing = $this->existing($organization, $actor, $operationId, 'invite', $fingerprint)) {
                return AiCommonSharedParticipant::query()->findOrFail($existing->result_id);
            }
            $currentCount = $shared->participants()->whereIn('status', [
                AiCommonSharedParticipant::STATUS_INVITED,
                AiCommonSharedParticipant::STATUS_ACTIVE,
            ])->count();
            $participant = $shared->participants()->where('user_id', $invitee->id)->lockForUpdate()->first();
            if ($participant && in_array($participant->status, [
                AiCommonSharedParticipant::STATUS_INVITED,
                AiCommonSharedParticipant::STATUS_ACTIVE,
            ], true)) {
                throw ValidationException::withMessages(['invitee_user_id' => 'このUserは招待済みまたは参加中です。']);
            }
            if ($currentCount >= 10) {
                throw ValidationException::withMessages(['invitee_user_id' => 'Shared ConversationはOwnerを含め最大10名です。']);
            }
            $values = [
                'invitation_public_id' => (string) Str::ulid(),
                'role' => AiCommonSharedParticipant::ROLE_PARTICIPANT,
                'status' => AiCommonSharedParticipant::STATUS_INVITED,
                'invited_by_user_id' => $actor->id,
                'invited_at' => now(),
                'accepted_at' => null,
                'accepted_membership_epoch' => null,
                'accepted_credential_generation' => null,
                'left_at' => null,
                'removed_at' => null,
                'removed_by_user_id' => null,
            ];
            if ($participant) {
                $values['audience_epoch'] = $participant->audience_epoch + 1;
                $values['version'] = $participant->version + 1;
                $participant->update($values);
            } else {
                $participant = $shared->participants()->create($values + [
                    'user_id' => $invitee->id,
                    'audience_epoch' => 1,
                    'version' => 1,
                ]);
            }
            $shared->increment('participant_version');
            $this->record($organization, $locked, $actor, $operationId, 'invite', $fingerprint, 'participant', $participant->id);

            return $participant->fresh();
        });
    }

    public function accept(User $actor, Organization $organization, AiCommonSharedParticipant $invitation): AiCommonSharedParticipant
    {
        return DB::transaction(function () use ($actor, $organization, $invitation): AiCommonSharedParticipant {
            $participant = AiCommonSharedParticipant::query()->lockForUpdate()->findOrFail($invitation->id);
            $membership = $this->access->authorizeInvitation($actor, $organization, $participant, true);
            $participant->update([
                'status' => AiCommonSharedParticipant::STATUS_ACTIVE,
                'accepted_membership_epoch' => $membership->access_epoch,
                'accepted_credential_generation' => $actor->credential_generation,
                'accepted_at' => now(),
                'version' => $participant->version + 1,
            ]);
            $participant->sharedConversation()->increment('participant_version');

            return $participant->fresh();
        });
    }

    public function remove(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedParticipant $target): void
    {
        DB::transaction(function () use ($actor, $organization, $conversation, $target): void {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $owner = $this->access->authorizeOwner($actor, $organization, $locked, true, true);
            $participant = AiCommonSharedParticipant::query()->lockForUpdate()->findOrFail($target->id);
            if ($participant->ai_common_shared_conversation_id !== $owner->ai_common_shared_conversation_id
                || $participant->role === AiCommonSharedParticipant::ROLE_OWNER) {
                throw ValidationException::withMessages(['participant' => 'Ownerは除外できません。']);
            }
            if (! in_array($participant->status, [AiCommonSharedParticipant::STATUS_ACTIVE, AiCommonSharedParticipant::STATUS_INVITED], true)) {
                return;
            }
            $participant->update([
                'status' => AiCommonSharedParticipant::STATUS_REMOVED,
                'audience_epoch' => $participant->audience_epoch + 1,
                'version' => $participant->version + 1,
                'removed_at' => now(),
                'removed_by_user_id' => $actor->id,
            ]);
            $owner->sharedConversation->increment('participant_version');
        });
    }

    public function leave(User $actor, Organization $organization, AiCommonConversation $conversation): void
    {
        DB::transaction(function () use ($actor, $organization, $conversation): void {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $participant = $this->access->authorizeParticipant($actor, $organization, $locked, false, true);
            if ($participant->role === AiCommonSharedParticipant::ROLE_OWNER) {
                throw ValidationException::withMessages(['participant' => 'Ownerは交代完了前に退出できません。']);
            }
            $participant->update([
                'status' => AiCommonSharedParticipant::STATUS_LEFT,
                'audience_epoch' => $participant->audience_epoch + 1,
                'version' => $participant->version + 1,
                'left_at' => now(),
            ]);
            $participant->sharedConversation->increment('participant_version');
        });
    }

    public function requestOwnerTransfer(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedParticipant $successor): void
    {
        DB::transaction(function () use ($actor, $organization, $conversation, $successor): void {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $owner = $this->access->authorizeOwner($actor, $organization, $locked, true, true);
            $target = AiCommonSharedParticipant::query()->lockForUpdate()->findOrFail($successor->id);
            if ($target->ai_common_shared_conversation_id !== $owner->ai_common_shared_conversation_id
                || $target->status !== AiCommonSharedParticipant::STATUS_ACTIVE
                || $target->role === AiCommonSharedParticipant::ROLE_OWNER) {
                throw ValidationException::withMessages(['participant' => '交代先は参加中のParticipantから選択してください。']);
            }
            $this->access->eligibleMembership($target->user()->firstOrFail(), $organization, true);
            $owner->sharedConversation->update([
                'pending_owner_user_id' => $target->user_id,
                'version' => $owner->sharedConversation->version + 1,
            ]);
        });
    }

    public function acceptOwnerTransfer(User $actor, Organization $organization, AiCommonConversation $conversation): void
    {
        DB::transaction(function () use ($actor, $organization, $conversation): void {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $successor = $this->access->authorizeParticipant($actor, $organization, $locked, true, true);
            $shared = $successor->sharedConversation;
            if ($shared->pending_owner_user_id !== $actor->id) {
                throw ValidationException::withMessages(['participant' => 'Owner交代の承諾待ちではありません。']);
            }
            $currentOwner = $shared->participants()->where('user_id', $shared->owner_user_id)->lockForUpdate()->firstOrFail();
            $currentOwner->update(['role' => AiCommonSharedParticipant::ROLE_PARTICIPANT, 'version' => $currentOwner->version + 1]);
            $successor->update(['role' => AiCommonSharedParticipant::ROLE_OWNER, 'version' => $successor->version + 1]);
            $shared->update([
                'owner_user_id' => $actor->id,
                'pending_owner_user_id' => null,
                'participant_version' => $shared->participant_version + 1,
                'version' => $shared->version + 1,
            ]);
        });
    }

    public function archive(User $actor, Organization $organization, AiCommonConversation $conversation): void
    {
        DB::transaction(function () use ($actor, $organization, $conversation): void {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->access->authorizeOwner($actor, $organization, $locked, false, true);
            if ($locked->status === AiCommonConversation::STATUS_ARCHIVED) {
                return;
            }
            $locked->update([
                'status' => AiCommonConversation::STATUS_ARCHIVED,
                'archived_at' => now(),
                'version' => $locked->version + 1,
            ]);
        });
    }

    private function operationId(array $input): string
    {
        $operationId = (string) ($input['operation_id'] ?? '');
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => '有効な操作IDが必要です。']);
        }

        return $operationId;
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function existing(Organization $organization, User $actor, string $operationId, string $command, string $fingerprint): ?AiCommonSharedOperation
    {
        $operation = AiCommonSharedOperation::query()
            ->where('organization_id', $organization->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation_id', $operationId)
            ->first();
        if (! $operation) {
            return null;
        }
        if ($operation->command !== $command || ! hash_equals($operation->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => '同じ操作IDを異なる内容には使用できません。']);
        }

        return $operation;
    }

    private function record(Organization $organization, AiCommonConversation $conversation, User $actor, string $operationId, string $command, string $fingerprint, string $resultType, int $resultId): void
    {
        AiCommonSharedOperation::query()->create([
            'organization_id' => $organization->id,
            'ai_common_conversation_id' => $conversation->id,
            'actor_user_id' => $actor->id,
            'operation_id' => $operationId,
            'command' => $command,
            'payload_fingerprint' => $fingerprint,
            'result_type' => $resultType,
            'result_id' => $resultId,
            'result_status' => AiCommonSharedOperation::RESULT_COMPLETED,
        ]);
    }
}
