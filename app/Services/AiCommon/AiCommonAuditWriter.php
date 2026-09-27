<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonAuditEvent;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonAuditWriter
{
    private const METADATA_KEYS = [
        'purpose', 'source_kind', 'source_revision_count', 'lineage_version',
        'proposal_operation', 'target_type', 'attempt', 'media_duration_ms',
        'usage_unit', 'usage_known',
    ];

    public function record(
        Organization $organization,
        ?AiCommonConversation $conversation,
        ?User $actor,
        string $operationId,
        string $event,
        string $subjectType,
        ?string $subjectPublicId,
        string $result,
        ?string $safeErrorCode = null,
        array $metadata = [],
    ): AiCommonAuditEvent {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid audit operation ID is required.']);
        }

        $metadata = Arr::only($metadata, self::METADATA_KEYS);
        ksort($metadata);
        $fingerprint = hash('sha256', json_encode([
            $organization->id, $conversation?->id, $actor?->id, $operationId,
            $event, $subjectType, $subjectPublicId, $result, $safeErrorCode, $metadata,
        ], JSON_THROW_ON_ERROR));

        $existing = AiCommonAuditEvent::query()
            ->where('organization_id', $organization->id)
            ->where('event', $event)
            ->where('operation_id', $operationId)
            ->first();
        if ($existing) {
            if (! hash_equals($existing->event_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['operation_id' => 'The audit operation ID is already final with different evidence.']);
            }

            return $existing;
        }

        return AiCommonAuditEvent::query()->create([
            'organization_id' => $organization->id,
            'ai_common_conversation_id' => $conversation?->id,
            'actor_user_id' => $actor?->id,
            'operation_id' => $operationId,
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'result' => $result,
            'safe_error_code' => $safeErrorCode,
            'metadata' => $metadata ?: null,
            'event_fingerprint' => $fingerprint,
            'occurred_at_utc' => now('UTC'),
        ]);
    }
}
