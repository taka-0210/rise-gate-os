<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonAttachment;
use App\Models\AiCommonAttachmentOperation;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\ProjectInternalNoteAttachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonAttachmentWriter
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const UPLOAD_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'mp3' => ['audio/mpeg'],
        'm4a' => ['audio/mp4', 'audio/x-m4a'],
        'mp4' => ['audio/mp4', 'video/mp4'],
        'webm' => ['audio/webm', 'video/webm'],
        'wav' => ['audio/wav', 'audio/x-wav'],
    ];

    public function __construct(private readonly AiCommonAttachmentAccess $access) {}

    public function reserveUpload(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        array $input,
    ): AiCommonAttachment {
        $canonical = $this->canonicalUpload($input);

        return $this->createIdempotently($actor, $organization, $conversation, 'reserve_upload', $canonical, function (
            int $accessEpoch,
        ) use ($actor, $organization, $conversation, $canonical): AiCommonAttachment {
            $publicId = (string) Str::ulid();

            return AiCommonAttachment::query()->create([
                'public_id' => $publicId,
                'organization_id' => $organization->id,
                'ai_common_conversation_id' => $conversation->id,
                'uploaded_by_user_id' => $actor->id,
                'variant' => AiCommonAttachment::VARIANT_UPLOAD,
                'state' => AiCommonAttachment::STATE_RECEIVING,
                'version' => 1,
                'display_name' => $canonical['display_name'],
                'mime_type' => $canonical['mime_type'],
                'extension' => $canonical['extension'],
                'size_bytes' => $canonical['size_bytes'],
                'storage_key' => $organization->public_id.'/'.$conversation->public_id.'/'.$publicId,
                'allows_ai_reference' => false,
                'ai_reference_version' => 1,
                'uploader_access_epoch' => $accessEpoch,
                'uploader_credential_generation' => $actor->credential_generation,
            ]);
        });
    }

    public function referenceExisting(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        ProjectInternalNoteAttachment $origin,
        array $input,
    ): AiCommonAttachment {
        $authorizedOrigin = $this->access->authorizeExistingOrigin($actor, $organization, $origin->public_id);
        $canonical = [
            'operation_id' => $this->operationId($input),
            'origin_public_id' => $authorizedOrigin->public_id,
            'origin_sha256' => $authorizedOrigin->sha256,
        ];

        return $this->createIdempotently($actor, $organization, $conversation, 'reference_existing', $canonical, function (
            int $accessEpoch,
        ) use ($actor, $organization, $conversation, $authorizedOrigin): AiCommonAttachment {
            return AiCommonAttachment::query()->create([
                'organization_id' => $organization->id,
                'ai_common_conversation_id' => $conversation->id,
                'uploaded_by_user_id' => $actor->id,
                'variant' => AiCommonAttachment::VARIANT_EXISTING,
                'state' => AiCommonAttachment::STATE_QUARANTINE,
                'version' => 1,
                'display_name' => $authorizedOrigin->original_name,
                'mime_type' => $authorizedOrigin->mime_type,
                'extension' => $authorizedOrigin->extension,
                'size_bytes' => $authorizedOrigin->size_bytes,
                'sha256' => $authorizedOrigin->sha256,
                'origin_type' => AiCommonAttachment::ORIGIN_PROJECT_INTERNAL_NOTE_ATTACHMENT,
                'origin_public_id' => $authorizedOrigin->public_id,
                'origin_sha256' => $authorizedOrigin->sha256,
                'allows_ai_reference' => false,
                'ai_reference_version' => 1,
                'uploader_access_epoch' => $accessEpoch,
                'uploader_credential_generation' => $actor->credential_generation,
            ]);
        });
    }

    public function revoke(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        array $input,
    ): AiCommonAttachment {
        $this->access->authorizeConversation($actor, $organization, $conversation);
        if ($attachment->organization_id !== $organization->id
            || $attachment->ai_common_conversation_id !== $conversation->id
            || $attachment->uploaded_by_user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => '取消理由を255文字以内で入力してください。']);
        }
        $canonical = ['operation_id' => $this->operationId($input), 'attachment_id' => $attachment->id, 'reason' => $reason];

        return $this->operateIdempotently($actor, $organization, $conversation, 'revoke', $canonical, function () use (
            $actor,
            $attachment,
            $reason,
        ): AiCommonAttachment {
            $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
            if ($locked->state !== AiCommonAttachment::STATE_REVOKED) {
                $locked->update([
                    'state' => AiCommonAttachment::STATE_REVOKED,
                    'version' => $locked->version + 1,
                    'revoked_at_utc' => now('UTC'),
                    'revoked_by_user_id' => $actor->id,
                    'revoke_reason' => $reason,
                ]);
            }

            return $locked->fresh();
        }, false);
    }

    public function setAiReference(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        array $input,
    ): AiCommonAttachment {
        $this->access->authorizeAttachment($actor, $organization, $conversation, $attachment, true);
        if ($attachment->uploaded_by_user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $canonical = [
            'operation_id' => $this->operationId($input),
            'attachment_id' => $attachment->id,
            'enabled' => $enabled,
        ];

        return $this->operateIdempotently(
            $actor,
            $organization,
            $conversation,
            'set_ai_reference',
            $canonical,
            function () use ($attachment, $enabled): AiCommonAttachment {
                $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
                if ((bool) $locked->allows_ai_reference !== $enabled) {
                    $locked->update([
                        'allows_ai_reference' => $enabled,
                        'ai_reference_version' => $locked->ai_reference_version + 1,
                    ]);
                }

                return $locked->fresh();
            },
        );
    }

    private function createIdempotently(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        string $command,
        array $canonical,
        callable $create,
    ): AiCommonAttachment {
        $this->access->authorizeConversation($actor, $organization, $conversation, true);
        $membership = app(AiCommonAccess::class)->authorizeOrganization($actor, $organization);

        return $this->operateIdempotently(
            $actor,
            $organization,
            $conversation,
            $command,
            $canonical,
            fn (): AiCommonAttachment => $create($membership->access_epoch),
        );
    }

    private function operateIdempotently(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        string $command,
        array $canonical,
        callable $operation,
        bool $requireActive = true,
    ): AiCommonAttachment {
        $operationId = $canonical['operation_id'];
        $fingerprint = hash('sha256', json_encode(Arr::except($canonical, ['operation_id']), JSON_THROW_ON_ERROR));
        $existing = $this->existingOperation($conversation, $actor, $operationId, $command, $fingerprint);
        if ($existing) {
            return $existing;
        }
        try {
            return DB::transaction(function () use (
                $actor,
                $organization,
                $conversation,
                $command,
                $operationId,
                $fingerprint,
                $operation,
                $requireActive,
            ): AiCommonAttachment {
                $lockedConversation = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
                $this->access->authorizeConversation($actor, $organization, $lockedConversation, $requireActive);
                if ($existing = $this->existingOperation($lockedConversation, $actor, $operationId, $command, $fingerprint)) {
                    return $existing;
                }
                $attachment = $operation();
                AiCommonAttachmentOperation::query()->create([
                    'organization_id' => $organization->id,
                    'ai_common_conversation_id' => $lockedConversation->id,
                    'actor_user_id' => $actor->id,
                    'ai_common_attachment_id' => $attachment->id,
                    'operation_id' => $operationId,
                    'command' => $command,
                    'payload_fingerprint' => $fingerprint,
                    'result_status' => AiCommonAttachmentOperation::RESULT_COMPLETED,
                ]);

                return $attachment;
            });
        } catch (QueryException $error) {
            if ($existing = $this->existingOperation($conversation, $actor, $operationId, $command, $fingerprint)) {
                return $existing;
            }
            throw $error;
        }
    }

    private function existingOperation(
        AiCommonConversation $conversation,
        User $actor,
        string $operationId,
        string $command,
        string $fingerprint,
    ): ?AiCommonAttachment {
        $existing = AiCommonAttachmentOperation::query()
            ->where('ai_common_conversation_id', $conversation->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation_id', $operationId)
            ->first();
        if (! $existing) {
            return null;
        }
        if ($existing->command !== $command || ! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => '同じ操作IDを異なる内容には使用できません。']);
        }

        return $existing->attachment()->firstOrFail();
    }

    private function canonicalUpload(array $input): array
    {
        $name = trim((string) ($input['display_name'] ?? ''));
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $mime = strtolower(trim((string) ($input['mime_type'] ?? '')));
        $size = filter_var($input['size_bytes'] ?? null, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name) > 255 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw ValidationException::withMessages(['display_name' => 'File名を確認してください。']);
        }
        if (! isset(self::UPLOAD_TYPES[$extension]) || ! in_array($mime, self::UPLOAD_TYPES[$extension], true)) {
            throw ValidationException::withMessages(['mime_type' => '許可されたFile形式と実体種別の組合せではありません。']);
        }
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['size_bytes' => 'Fileは10 MiB以内にしてください。']);
        }

        return [
            'operation_id' => $this->operationId($input),
            'display_name' => $name,
            'extension' => $extension,
            'mime_type' => $mime,
            'size_bytes' => $size,
        ];
    }

    private function operationId(array $input): string
    {
        $operationId = (string) ($input['operation_id'] ?? '');
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => '有効な操作IDが必要です。']);
        }

        return $operationId;
    }
}
