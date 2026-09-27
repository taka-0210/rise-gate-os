<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonAttachmentExtractor;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonAttachmentDerivative;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonAttachmentDeriver
{
    public function __construct(
        private readonly AiCommonAttachmentAccess $access,
        private readonly AiCommonAttachmentExtractor $extractor,
    ) {}

    public function extract(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        string $operationId,
        array $selector,
    ): AiCommonAttachmentDerivative {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid operation ID is required.']);
        }
        $this->access->authorizeConversation($actor, $organization, $conversation, true);
        $binary = $this->access->binary($actor, $organization, $conversation, $attachment);
        ksort($selector);
        $selectorFingerprint = hash('sha256', json_encode($selector, JSON_THROW_ON_ERROR));
        $fingerprint = hash('sha256', implode('|', [
            $attachment->id,
            $attachment->version,
            $attachment->sha256,
            $selectorFingerprint,
        ]));
        if ($existing = $this->existing($attachment, $actor, $operationId, $fingerprint)) {
            return $existing;
        }

        try {
            $result = $this->extractor->extract($binary, (string) $attachment->extension, $selector);
            $attributes = [
                'state' => AiCommonAttachmentDerivative::STATE_READY,
                'selector' => $result['selector'],
                'extractor_driver' => $result['driver'],
                'extractor_version' => $result['version'],
                'content' => $result['content'],
                'content_sha256' => hash('sha256', $result['content']),
                'character_count' => mb_strlen($result['content']),
            ];
        } catch (AiCommonExtractionException $error) {
            $attributes = [
                'state' => AiCommonAttachmentDerivative::STATE_FAILED,
                'selector' => $selector,
                'safe_error_code' => $error->safeCode,
            ];
        }

        try {
            return DB::transaction(function () use (
                $actor,
                $organization,
                $conversation,
                $attachment,
                $operationId,
                $fingerprint,
                $selectorFingerprint,
                $attributes,
            ): AiCommonAttachmentDerivative {
                $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
                $this->access->authorizeConversation($actor->fresh(), $organization, $conversation->fresh(), true);
                $this->access->authorizeAttachment($actor->fresh(), $organization, $conversation->fresh(), $locked, true);
                if ($locked->version !== $attachment->version
                    || ! hash_equals((string) $locked->sha256, (string) $attachment->sha256)) {
                    throw ValidationException::withMessages(['attachment' => 'Attachment changed during extraction.']);
                }
                if ($existing = $this->existing($locked, $actor, $operationId, $fingerprint)) {
                    return $existing;
                }

                return AiCommonAttachmentDerivative::query()->create($attributes + [
                    'ai_common_attachment_id' => $locked->id,
                    'created_by_user_id' => $actor->id,
                    'operation_id' => $operationId,
                    'payload_fingerprint' => $fingerprint,
                    'kind' => AiCommonAttachmentDerivative::KIND_TEXT_EXTRACT,
                    'attachment_version' => $locked->version,
                    'source_sha256' => $locked->sha256,
                    'selector_fingerprint' => $selectorFingerprint,
                ]);
            }, 3);
        } catch (QueryException $error) {
            if ($existing = $this->existing($attachment, $actor, $operationId, $fingerprint)) {
                return $existing;
            }
            throw $error;
        }
    }

    private function existing(
        AiCommonAttachment $attachment,
        User $actor,
        string $operationId,
        string $fingerprint,
    ): ?AiCommonAttachmentDerivative {
        $existing = AiCommonAttachmentDerivative::query()
            ->where('ai_common_attachment_id', $attachment->id)
            ->where('created_by_user_id', $actor->id)
            ->where('operation_id', $operationId)
            ->first();
        if ($existing && ! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => 'The operation ID is already used for another extraction.']);
        }

        return $existing;
    }
}
