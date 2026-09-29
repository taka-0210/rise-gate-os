<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedIdentityRevision;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSpeakerRelation;
use App\Models\AiCommonSharedTranscriptRevision;
use App\Models\AiCommonSharedTranscriptSegment;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonSharedTranscriptWriter
{
    public function __construct(
        private readonly AiCommonSharedSessionAccess $access,
        private readonly AiCommonSharedLongContext $longContext,
    ) {}

    public function revise(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        AiCommonSharedTranscriptSegment $segment,
        string $operationId,
        string $content,
    ): AiCommonSharedTranscriptRevision {
        $content = trim($content);
        if (! Str::isUuid($operationId) || $content === '' || mb_strlen($content) > 4000) {
            throw ValidationException::withMessages(['transcript' => 'A valid operation and corrected text within 4,000 characters are required.']);
        }
        $this->access->authorize($actor, $organization, $conversation, $session);
        if ($segment->ai_common_shared_session_id !== $session->id) {
            throw ValidationException::withMessages(['transcript' => 'Transcript segment is outside this Session.']);
        }
        $fingerprint = hash('sha256', json_encode([$segment->id, $content], JSON_THROW_ON_ERROR));

        $revision = DB::transaction(function () use ($actor, $organization, $conversation, $session, $segment, $operationId, $content, $fingerprint): AiCommonSharedTranscriptRevision {
            $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->access->authorize($actor, $organization, $conversation, $lockedSession);
            $locked = AiCommonSharedTranscriptSegment::query()->lockForUpdate()->findOrFail($segment->id);
            if ($locked->ai_common_shared_session_id !== $lockedSession->id) {
                throw ValidationException::withMessages(['transcript' => 'Transcript segment is outside this Session.']);
            }
            if ($existing = $locked->revisions()->where('operation_id', $operationId)->first()) {
                if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['operation_id' => 'Transcript operation was reused with different content.']);
                }

                return $existing;
            }
            $current = $locked->currentRevision()->firstOrFail();
            $revision = $locked->revisions()->create([
                'parent_revision_id' => $current->id,
                'created_by_user_id' => $actor->id,
                'revision_no' => $current->revision_no + 1,
                'kind' => AiCommonSharedTranscriptRevision::KIND_HUMAN,
                'operation_id' => $operationId,
                'payload_fingerprint' => $fingerprint,
                'content' => $content,
                'content_sha256' => hash('sha256', $content),
                'range_start_ms' => $current->range_start_ms,
                'range_end_ms' => $current->range_end_ms,
            ]);
            $locked->update(['current_revision_id' => $revision->id]);

            return $revision;
        }, 3);
        $this->longContext->markDirty($revision);

        return $revision;
    }

    public function confirmSelfIdentity(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        AiCommonSharedTranscriptSegment $segment,
        string $operationId,
    ): AiCommonSharedIdentityRevision {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid identity confirmation operation is required.']);
        }
        $this->access->authorize($actor, $organization, $conversation, $session);
        if ($segment->ai_common_shared_session_id !== $session->id) {
            throw ValidationException::withMessages(['identity' => 'Transcript segment is outside this Session.']);
        }

        $identity = DB::transaction(function () use ($actor, $organization, $conversation, $session, $segment, $operationId): AiCommonSharedIdentityRevision {
            $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->access->authorize($actor, $organization, $conversation, $lockedSession);
            $locked = AiCommonSharedTranscriptSegment::query()->lockForUpdate()->findOrFail($segment->id);
            if ($existing = $locked->identityRevisions()->where('operation_id', $operationId)->first()) {
                return $existing;
            }
            $parent = $locked->identityRevisions()->orderByDesc('revision_no')->first();

            return $locked->identityRevisions()->create([
                'parent_revision_id' => $parent?->id,
                'confirmed_user_id' => $actor->id,
                'created_by_user_id' => $actor->id,
                'revision_no' => ($parent?->revision_no ?? 0) + 1,
                'operation_id' => $operationId,
                'status' => AiCommonSharedIdentityRevision::STATUS_CONFIRMED,
                'evidence_type' => 'human_self_confirmation',
                'confirmed_at_utc' => now(),
            ]);
        }, 3);
        $this->longContext->markDirty($segment->fresh()->currentRevision);

        return $identity;
    }

    public function relateSpeakers(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        AiCommonSharedTranscriptSegment $from,
        AiCommonSharedTranscriptSegment $to,
        string $operationId,
        string $evidenceReference,
    ): AiCommonSharedSpeakerRelation {
        $evidenceReference = trim($evidenceReference);
        if (! Str::isUuid($operationId) || $evidenceReference === '' || mb_strlen($evidenceReference) > 160) {
            throw ValidationException::withMessages(['speaker' => 'Explicit continuity evidence is required.']);
        }
        $this->access->authorize($actor, $organization, $conversation, $session);
        if ($from->ai_common_shared_session_id !== $session->id
            || $to->ai_common_shared_session_id !== $session->id
            || ($from->source_kind === AiCommonSharedTranscriptSegment::SOURCE_BOUNDED
                && $to->source_kind === AiCommonSharedTranscriptSegment::SOURCE_BOUNDED
                && $from->ai_common_shared_audio_window_id === $to->ai_common_shared_audio_window_id)
            || $from->id === $to->id) {
            throw ValidationException::withMessages(['speaker' => 'Cross-window continuity requires two distinct Session segments.']);
        }
        $existing = AiCommonSharedSpeakerRelation::query()
            ->where('ai_common_shared_session_id', $session->id)
            ->where('operation_id', $operationId)->first();
        if ($existing) {
            if ($existing->from_segment_id !== $from->id || $existing->to_segment_id !== $to->id
                || $existing->evidence_reference !== $evidenceReference) {
                throw ValidationException::withMessages(['operation_id' => 'Speaker relation operation was reused with different evidence.']);
            }

            return $existing;
        }

        return AiCommonSharedSpeakerRelation::query()->create([
            'ai_common_shared_session_id' => $session->id,
            'from_segment_id' => $from->id,
            'to_segment_id' => $to->id,
            'created_by_user_id' => $actor->id,
            'operation_id' => $operationId,
            'evidence_type' => 'explicit_human_evidence',
            'evidence_reference' => $evidenceReference,
            'status' => 'confirmed',
        ]);
    }
}
