<?php

namespace App\Services\AiCommon\Realtime;

use App\Models\AiCommonSharedCaptureStream;
use App\Models\AiCommonSharedProviderSendRange;
use App\Models\AiCommonSharedProviderSession;
use App\Models\AiCommonSharedRelayLease;
use App\Models\AiCommonSharedSourceRange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RealtimeSourceLedger
{
    public function accept(CanonicalAudioFrame $frame, AiCommonSharedRelayLease $lease): AiCommonSharedSourceRange
    {
        return DB::transaction(function () use ($frame, $lease): AiCommonSharedSourceRange {
            $lockedStream = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($lease->ai_common_shared_capture_stream_id);
            $locked = AiCommonSharedRelayLease::query()->lockForUpdate()->findOrFail($lease->id);
            if ($locked->state !== AiCommonSharedRelayLease::STATE_ACTIVE || $locked->expires_at_utc->lte(now())
                || $lockedStream->state !== AiCommonSharedCaptureStream::STATE_RECORDING
                || $lockedStream->generation !== $locked->generation
                || $lockedStream->id !== $locked->ai_common_shared_capture_stream_id
                || ! hash_equals($locked->public_id, $frame->leaseId) || $locked->generation !== $frame->generation) {
                throw ValidationException::withMessages(['frame' => SafeRealtimeReason::LeaseExpired->value]);
            }
            $identity = AiCommonSharedSourceRange::query()->where('ai_common_shared_capture_stream_id', $locked->ai_common_shared_capture_stream_id)
                ->where('generation', $frame->generation)
                ->where(fn ($query) => $query->where('frame_sequence', $frame->sequence)->orWhere('client_event_id', $frame->clientEventId))
                ->lockForUpdate()->first();
            if ($identity) {
                if ($identity->client_event_id === $frame->clientEventId
                    && $identity->frame_sequence === $frame->sequence
                    && $identity->start_sample === $frame->startSample && $identity->end_sample === $frame->endSample
                    && hash_equals($identity->content_sha256, $frame->contentSha256)) {
                    return $identity;
                }
                throw ValidationException::withMessages(['frame' => SafeRealtimeReason::IntegrityConflict->value]);
            }
            $previousEnd = (int) (AiCommonSharedSourceRange::query()->where('ai_common_shared_capture_stream_id', $locked->ai_common_shared_capture_stream_id)
                ->where('state', 'accepted')->max('end_sample') ?? 0);
            if ($frame->startSample !== $previousEnd) {
                throw ValidationException::withMessages(['frame' => $frame->startSample > $previousEnd ? SafeRealtimeReason::SourceGap->value : SafeRealtimeReason::SourceOverlap->value]);
            }

            return AiCommonSharedSourceRange::query()->create([
                'ai_common_shared_session_id' => $locked->ai_common_shared_session_id,
                'ai_common_shared_capture_stream_id' => $locked->ai_common_shared_capture_stream_id,
                'relay_lease_id' => $locked->id, 'generation' => $frame->generation,
                'frame_sequence' => $frame->sequence, 'client_event_id' => $frame->clientEventId,
                'start_sample' => $frame->startSample, 'end_sample' => $frame->endSample,
                'sample_rate' => $frame->sampleRate, 'bit_depth' => $frame->bitDepth,
                'channels' => $frame->channels, 'format' => $frame->format,
                'content_sha256' => $frame->contentSha256,
                'authorization_fingerprint' => $locked->audience_fingerprint,
                'consent_fingerprint' => $locked->consent_fingerprint,
                'state' => 'accepted', 'received_at_utc' => now(), 'accepted_at_utc' => now(),
            ]);
        }, 3);
    }

    public function sent(AiCommonSharedProviderSession $providerSession, AiCommonSharedSourceRange $range): AiCommonSharedProviderSendRange
    {
        return DB::transaction(function () use ($providerSession, $range): AiCommonSharedProviderSendRange {
            if ($providerSession->ai_common_shared_capture_stream_id !== $range->ai_common_shared_capture_stream_id
                || $providerSession->generation !== $range->generation || $range->state !== 'accepted') {
                throw ValidationException::withMessages(['range' => SafeRealtimeReason::ProviderMappingUnverified->value]);
            }
            $existing = AiCommonSharedProviderSendRange::query()->where('provider_session_id', $providerSession->id)->where('source_range_id', $range->id)->first();
            if ($existing) {
                return $existing;
            }
            $start = (int) (AiCommonSharedProviderSendRange::query()->where('provider_session_id', $providerSession->id)->max('provider_offset_end_sample') ?? 0);

            return AiCommonSharedProviderSendRange::query()->create([
                'provider_session_id' => $providerSession->id, 'source_range_id' => $range->id,
                'send_ordinal' => (int) AiCommonSharedProviderSendRange::query()->where('provider_session_id', $providerSession->id)->max('send_ordinal') + 1,
                'provider_offset_start_sample' => $start,
                'provider_offset_end_sample' => $start + ($range->end_sample - $range->start_sample),
                'state' => 'sent', 'sent_at_utc' => now(),
            ]);
        }, 3);
    }

    public function sourceMapping(AiCommonSharedProviderSession $providerSession, int $providerStart, int $providerEnd): array
    {
        $rows = AiCommonSharedProviderSendRange::query()->where('provider_session_id', $providerSession->id)
            ->where('provider_offset_start_sample', '>=', $providerStart)
            ->where('provider_offset_end_sample', '<=', $providerEnd)
            ->with('sourceRange')->orderBy('send_ordinal')->get();
        if ($rows->isEmpty() || $rows->first()->provider_offset_start_sample !== $providerStart
            || $rows->last()->provider_offset_end_sample !== $providerEnd) {
            return ['verification_state' => 'unverified'];
        }
        for ($i = 1; $i < $rows->count(); $i++) {
            if ($rows[$i - 1]->provider_offset_end_sample !== $rows[$i]->provider_offset_start_sample
                || $rows[$i - 1]->sourceRange->end_sample !== $rows[$i]->sourceRange->start_sample) {
                return ['verification_state' => 'unverified'];
            }
        }

        return ['verification_state' => 'verified', 'start_sample' => $rows->first()->sourceRange->start_sample, 'end_sample' => $rows->last()->sourceRange->end_sample];
    }
}
