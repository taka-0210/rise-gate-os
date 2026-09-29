<?php

namespace App\Services\AiCommon\Realtime;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedDurableFinalCommit;
use App\Models\AiCommonSharedDurableFinalCommitItem;
use App\Models\AiCommonSharedProviderEventReceipt;
use App\Models\AiCommonSharedProviderSendRange;
use App\Models\AiCommonSharedProviderSession;
use App\Models\AiCommonSharedRelayLease;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\AiCommonSharedTranscriptRevision;
use App\Models\AiCommonSharedTranscriptSegment;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedLongContext;
use App\Services\AiCommon\AiCommonSharedSessionAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RealtimeDurableFinalCommitter
{
    public function __construct(
        private readonly AiCommonSharedSessionAccess $access,
        private readonly AiCommonSharedLongContext $longContext,
        private readonly RealtimeTranscriptSourceGuard $sourceGuard,
    ) {}

    public function commit(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedProviderEventReceipt $receipt, string $operationId): AiCommonSharedDurableFinalCommit
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid Durable Final operation is required.']);
        }
        $this->authorize($actor, $organization, $conversation, $session);
        $revision = null;
        $commit = DB::transaction(function () use ($actor, $organization, $conversation, $session, $receipt, $operationId, &$revision): AiCommonSharedDurableFinalCommit {
            $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $lockedReceipt = AiCommonSharedProviderEventReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $provider = AiCommonSharedProviderSession::query()->lockForUpdate()->findOrFail($lockedReceipt->provider_session_id);
            $lease = AiCommonSharedRelayLease::query()->lockForUpdate()->findOrFail($provider->relay_lease_id);
            $this->authorize($actor->fresh(), $organization, $conversation->fresh(), $lockedSession);

            $byReceipt = AiCommonSharedDurableFinalCommit::query()->where('provider_event_receipt_id', $lockedReceipt->id)->first();
            $byOperation = AiCommonSharedDurableFinalCommit::query()->where('writer_operation_id', $operationId)->first();
            if ($byReceipt || $byOperation) {
                if (! $byReceipt || ! $byOperation || $byReceipt->id !== $byOperation->id || $byReceipt->state !== 'committed') {
                    throw ValidationException::withMessages(['operation_id' => 'Durable Final identity was reused with different lineage.']);
                }

                return $byReceipt;
            }

            $metadata = $lockedReceipt->normalized_final_metadata;
            $start = $lockedReceipt->verified_source_start_sample;
            $end = $lockedReceipt->verified_source_end_sample;
            $content = trim((string) ($metadata['content'] ?? ''));
            if ($lockedReceipt->normalized_event_type !== 'final' || $lockedReceipt->status !== 'accepted'
                || $lockedReceipt->range_verification_state !== 'verified' || $start === null || $end === null || $end <= $start
                || $content === '' || ! hash_equals((string) $lockedReceipt->final_content_sha256, hash('sha256', $content))
                || $provider->ai_common_shared_capture_stream_id !== $lease->ai_common_shared_capture_stream_id
                || $provider->generation !== $lease->generation || $lease->ai_common_shared_session_id !== $lockedSession->id
                || $lease->state !== AiCommonSharedRelayLease::STATE_ACTIVE || $lease->expires_at_utc->lte(now())) {
                $this->failLineage();
            }

            $ranges = AiCommonSharedProviderSendRange::query()->where('provider_session_id', $provider->id)
                ->with('sourceRange')->orderBy('send_ordinal')->lockForUpdate()->get()
                ->filter(fn ($row) => $row->sourceRange && $row->sourceRange->end_sample > $start && $row->sourceRange->start_sample < $end)->values();
            if ($ranges->isEmpty() || $ranges->first()->sourceRange->start_sample > $start || $ranges->last()->sourceRange->end_sample < $end) {
                $this->failLineage();
            }
            $cursor = $start;
            foreach ($ranges as $row) {
                $range = $row->sourceRange;
                if ($range->state !== 'accepted' || $range->start_sample > $cursor || $range->end_sample <= $cursor
                    || $range->ai_common_shared_session_id !== $lockedSession->id
                    || $range->ai_common_shared_capture_stream_id !== $provider->ai_common_shared_capture_stream_id
                    || $range->generation !== $provider->generation
                    || ! hash_equals($range->authorization_fingerprint, $lease->audience_fingerprint)
                    || ! hash_equals($range->consent_fingerprint, $lease->consent_fingerprint)) {
                    $this->failLineage();
                }
                $cursor = min($end, $range->end_sample);
            }
            if ($cursor !== $end) {
                $this->failLineage();
            }

            $commit = AiCommonSharedDurableFinalCommit::query()->create([
                'provider_event_receipt_id' => $lockedReceipt->id, 'writer_operation_id' => $operationId,
                'validated_source_start_sample' => $start, 'validated_source_end_sample' => $end, 'state' => 'committing',
            ]);
            $speaker = collect($metadata['speakers'] ?? [])->filter()->first() ?? AiCommonSharedTranscriptSegment::SPEAKER_UNKNOWN;
            $startMs = intdiv($start * 1000, 16000);
            $endMs = (int) ceil($end * 1000 / 16000);
            $segment = $this->sourceGuard->within($lockedSession->id, $provider->ai_common_shared_capture_stream_id, $commit->id,
                fn () => AiCommonSharedTranscriptSegment::query()->create([
                    'ai_common_shared_session_id' => $lockedSession->id, 'ai_common_shared_audio_window_id' => null,
                    'ai_common_shared_capture_stream_id' => $provider->ai_common_shared_capture_stream_id,
                    'source_kind' => AiCommonSharedTranscriptSegment::SOURCE_REALTIME,
                    'realtime_durable_final_commit_id' => $commit->id,
                    'segment_index' => ((int) $lockedSession->transcriptSegments()->max('segment_index')) + 1,
                    'speaker_label' => mb_substr((string) $speaker, 0, 24),
                    'speaker_scope' => mb_substr('realtime:'.$provider->public_id.':'.$speaker, 0, 80),
                    'range_start_ms' => $startMs, 'range_end_ms' => $endMs,
                    'confidence' => collect($metadata['words'] ?? [])->pluck('confidence')->filter(fn ($value) => is_numeric($value))->avg(),
                ]));
            $revision = AiCommonSharedTranscriptRevision::query()->create([
                'ai_common_shared_transcript_segment_id' => $segment->id, 'created_by_user_id' => null,
                'revision_no' => 1, 'kind' => AiCommonSharedTranscriptRevision::KIND_PROVIDER,
                'operation_id' => $operationId,
                'payload_fingerprint' => hash('sha256', $lockedReceipt->public_id.'|'.$content.'|'.$start.'|'.$end),
                'content' => $content, 'content_sha256' => hash('sha256', $content),
                'range_start_ms' => $startMs, 'range_end_ms' => $endMs,
                'provider' => $provider->adapter, 'model' => config('ai-common-realtime.deepgram.model'),
            ]);
            $segment->update(['current_revision_id' => $revision->id]);
            AiCommonSharedDurableFinalCommitItem::query()->create([
                'durable_final_commit_id' => $commit->id, 'transcript_segment_id' => $segment->id,
                'transcript_revision_id' => $revision->id, 'ordinal' => 1,
            ]);
            $commit->update(['state' => 'committed', 'committed_at_utc' => now()]);

            return $commit->fresh();
        }, 3);
        if ($revision) {
            $this->longContext->markDirty($revision);
        }

        return $commit;
    }

    private function authorize(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session): void
    {
        $this->access->authorize($actor, $organization, $conversation, $session, true);
        $this->access->assertConsents($actor, $organization, $conversation, $session, [
            AiCommonSharedSessionConsent::PURPOSE_RECORDING,
            AiCommonSharedSessionConsent::PURPOSE_EXTERNAL_ASR,
            AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
        ]);
    }

    private function failLineage(): never
    {
        throw ValidationException::withMessages(['provider_event' => SafeRealtimeReason::ProviderMappingUnverified->value]);
    }
}
