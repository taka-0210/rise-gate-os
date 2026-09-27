<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\AiCommonInputOperation;
use App\Models\AiCommonMessage;
use App\Models\AiCommonSharedMessageAuthor;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonHumanMessageWriter
{
    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly AiCommonSharedAccess $sharedAccess,
        private readonly AiCommonAttachmentAccess $attachments,
    ) {}

    public function post(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        array $input,
    ): AiCommonMessage {
        return $this->write($actor, $organization, $conversation, $input, false);
    }

    public function postShared(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        array $input,
    ): AiCommonMessage {
        return $this->write($actor, $organization, $conversation, $input, true);
    }

    private function write(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        array $input,
        bool $shared,
    ): AiCommonMessage {
        $operationId = (string) ($input['operation_id'] ?? '');
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => '有効な操作IDが必要です。']);
        }
        $content = trim((string) ($input['content'] ?? ''));
        if ($content === '' || mb_strlen($content) > 4000) {
            throw ValidationException::withMessages(['content' => 'Messageは1文字以上4000文字以内で入力してください。']);
        }
        $attachmentIds = collect($input['attachment_ids'] ?? [])->map(fn ($id): int => (int) $id)
            ->filter()->unique()->sort()->values();
        if ($attachmentIds->count() > 5) {
            throw ValidationException::withMessages(['attachments' => 'Attachmentは1回5件までです。']);
        }
        if ($shared && $attachmentIds->isNotEmpty()) {
            throw ValidationException::withMessages(['attachments' => 'Shared Attachment接続はP2以降で有効化します。']);
        }
        $fingerprint = hash('sha256', json_encode([
            'content' => $content,
            'attachment_ids' => $attachmentIds->all(),
        ], JSON_THROW_ON_ERROR));

        if ($shared) {
            $this->sharedAccess->authorizeParticipant($actor, $organization, $conversation, true);
        } else {
            $this->common->authorizeConversation($actor, $organization, $conversation);
        }
        if ($existing = $this->existing($conversation, $actor, $operationId, $fingerprint)) {
            return $existing;
        }
        try {
            return DB::transaction(function () use (
                $actor,
                $organization,
                $conversation,
                $operationId,
                $content,
                $attachmentIds,
                $fingerprint,
                $shared,
            ): AiCommonMessage {
                $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
                $sharedParticipant = $shared
                    ? $this->sharedAccess->authorizeParticipant($actor, $organization, $locked, true, true)
                    : null;
                if (! $shared) {
                    $this->common->authorizeConversation($actor, $organization, $locked, true);
                }
                if ($existing = $this->existing($locked, $actor, $operationId, $fingerprint)) {
                    return $existing;
                }
                $selected = $locked->attachments()->whereIn('id', $attachmentIds)->get();
                if ($selected->count() !== $attachmentIds->count()) {
                    throw ValidationException::withMessages(['attachments' => '選択したAttachmentを利用できません。']);
                }
                foreach ($selected as $attachment) {
                    $this->attachments->authorizeAttachment($actor, $organization, $locked, $attachment, true);
                }
                $message = $locked->messages()->create([
                    'role' => AiCommonMessage::ROLE_USER,
                    'content' => $content,
                    'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
                ]);
                if ($sharedParticipant) {
                    $membership = $this->common->authorizeOrganization($actor, $organization);
                    AiCommonSharedMessageAuthor::query()->create([
                        'ai_common_message_id' => $message->id,
                        'ai_common_shared_conversation_id' => $sharedParticipant->ai_common_shared_conversation_id,
                        'ai_common_shared_participant_id' => $sharedParticipant->id,
                        'author_user_id' => $actor->id,
                        'participant_audience_epoch' => $sharedParticipant->audience_epoch,
                        'membership_access_epoch' => $membership->access_epoch,
                        'credential_generation' => $actor->credential_generation,
                    ]);
                }
                $message->attachments()->sync($selected->mapWithKeys(
                    fn (AiCommonAttachment $attachment): array => [$attachment->id => ['attachment_version' => $attachment->version]]
                ));
                $locked->update(['last_message_at' => now(), 'version' => $locked->version + 1]);
                AiCommonInputOperation::query()->create([
                    'organization_id' => $organization->id,
                    'ai_common_conversation_id' => $locked->id,
                    'actor_user_id' => $actor->id,
                    'ai_common_message_id' => $message->id,
                    'operation_id' => $operationId,
                    'command' => AiCommonInputOperation::COMMAND_HUMAN_MESSAGE,
                    'payload_fingerprint' => $fingerprint,
                    'result_status' => AiCommonInputOperation::RESULT_COMPLETED,
                ]);

                return $message;
            });
        } catch (QueryException $error) {
            if ($existing = $this->existing($conversation, $actor, $operationId, $fingerprint)) {
                return $existing;
            }
            throw $error;
        }
    }

    private function existing(
        AiCommonConversation $conversation,
        User $actor,
        string $operationId,
        string $fingerprint,
    ): ?AiCommonMessage {
        $operation = AiCommonInputOperation::query()
            ->where('ai_common_conversation_id', $conversation->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation_id', $operationId)
            ->first();
        if (! $operation) {
            return null;
        }
        if ($operation->command !== AiCommonInputOperation::COMMAND_HUMAN_MESSAGE
            || ! hash_equals($operation->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => '同じ操作IDを異なる内容には使用できません。']);
        }

        return $operation->message()->firstOrFail();
    }
}
