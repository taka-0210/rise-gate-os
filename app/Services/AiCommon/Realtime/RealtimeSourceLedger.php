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
        return DB::transaction(fn (): AiCommonSharedSourceRange => $this->acceptLocked($frame, $lease), 3);
    }

    /**
     * Keep each 100 ms Source Range while amortizing relay-to-app transport.
     * The batch is atomic: one invalid frame rolls the entire request back.
     *
     * @param  list<CanonicalAudioFrame>  $frames
     * @return list<AiCommonSharedSourceRange>
     */
    public function acceptBatch(array $frames, AiCommonSharedRelayLease $lease): array
    {
        if ($frames === [] || count($frames) > 10) {
            throw ValidationException::withMessages(['frames' => 'Realtime frame batch must contain between 1 and 10 frames.']);
        }

        return DB::transaction(function () use ($frames, $lease): array {
            $lockedStream = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($lease->ai_common_shared_capture_stream_id);
            $lockedLease = AiCommonSharedRelayLease::query()->lockForUpdate()->findOrFail($lease->id);
            foreach ($frames as $frame) {
                $this->assertFrameScope($frame, $lockedLease, $lockedStream);
            }

            $sequences = array_map(static fn (CanonicalAudioFrame $frame): int => $frame->sequence, $frames);
            $clientEventIds = array_map(static fn (CanonicalAudioFrame $frame): string => $frame->clientEventId, $frames);
            $identities = AiCommonSharedSourceRange::query()
                ->where('ai_common_shared_capture_stream_id', $lockedLease->ai_common_shared_capture_stream_id)
                ->where('generation', $lockedLease->generation)
                ->where(fn ($query) => $query->whereIn('frame_sequence', $sequences)->orWhereIn('client_event_id', $clientEventIds))
                ->lockForUpdate()->get();
            $previousEnd = (int) (AiCommonSharedSourceRange::query()
                ->where('ai_common_shared_capture_stream_id', $lockedLease->ai_common_shared_capture_stream_id)
                ->where('state', 'accepted')->max('end_sample') ?? 0);
            $accepted = [];
            foreach ($frames as $frame) {
                $identity = $identities->first(fn (AiCommonSharedSourceRange $range): bool => $range->frame_sequence === $frame->sequence || $range->client_event_id === $frame->clientEventId);
                if ($identity) {
                    if (! $this->sameFrame($identity, $frame)) {
                        throw ValidationException::withMessages(['frame' => SafeRealtimeReason::IntegrityConflict->value]);
                    }
                    $accepted[] = $identity;

                    continue;
                }
                if ($frame->startSample !== $previousEnd) {
                    throw ValidationException::withMessages(['frame' => $frame->startSample > $previousEnd ? SafeRealtimeReason::SourceGap->value : SafeRealtimeReason::SourceOverlap->value]);
                }
                $identity = $this->createRange($frame, $lockedLease);
                $identities->push($identity);
                $accepted[] = $identity;
                $previousEnd = $frame->endSample;
            }

            return $accepted;
        }, 3);
    }

    private function acceptLocked(CanonicalAudioFrame $frame, AiCommonSharedRelayLease $lease): AiCommonSharedSourceRange
    {
        $lockedStream = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($lease->ai_common_shared_capture_stream_id);
        $locked = AiCommonSharedRelayLease::query()->lockForUpdate()->findOrFail($lease->id);
        $this->assertFrameScope($frame, $locked, $lockedStream);
        $identity = AiCommonSharedSourceRange::query()->where('ai_common_shared_capture_stream_id', $locked->ai_common_shared_capture_stream_id)
            ->where('generation', $frame->generation)
            ->where(fn ($query) => $query->where('frame_sequence', $frame->sequence)->orWhere('client_event_id', $frame->clientEventId))
            ->lockForUpdate()->first();
        if ($identity) {
            if ($this->sameFrame($identity, $frame)) {
                return $identity;
            }
            throw ValidationException::withMessages(['frame' => SafeRealtimeReason::IntegrityConflict->value]);
        }
        $previousEnd = (int) (AiCommonSharedSourceRange::query()->where('ai_common_shared_capture_stream_id', $locked->ai_common_shared_capture_stream_id)
            ->where('state', 'accepted')->max('end_sample') ?? 0);
        if ($frame->startSample !== $previousEnd) {
            throw ValidationException::withMessages(['frame' => $frame->startSample > $previousEnd ? SafeRealtimeReason::SourceGap->value : SafeRealtimeReason::SourceOverlap->value]);
        }

        return $this->createRange($frame, $locked);
    }

    private function assertFrameScope(CanonicalAudioFrame $frame, AiCommonSharedRelayLease $lease, AiCommonSharedCaptureStream $stream): void
    {
        if ($lease->state !== AiCommonSharedRelayLease::STATE_ACTIVE || $lease->expires_at_utc->lte(now())
            || $stream->state !== AiCommonSharedCaptureStream::STATE_RECORDING
            || $stream->generation !== $lease->generation
            || $stream->id !== $lease->ai_common_shared_capture_stream_id
            || ! hash_equals($lease->public_id, $frame->leaseId)
            || ! hash_equals($stream->public_id, $frame->streamId)
            || $lease->generation !== $frame->generation) {
            throw ValidationException::withMessages(['frame' => SafeRealtimeReason::LeaseExpired->value]);
        }
    }

    private function sameFrame(AiCommonSharedSourceRange $range, CanonicalAudioFrame $frame): bool
    {
        return $range->client_event_id === $frame->clientEventId
            && $range->frame_sequence === $frame->sequence
            && $range->start_sample === $frame->startSample && $range->end_sample === $frame->endSample
            && hash_equals($range->content_sha256, $frame->contentSha256);
    }

    private function createRange(CanonicalAudioFrame $frame, AiCommonSharedRelayLease $lease): AiCommonSharedSourceRange
    {
        return AiCommonSharedSourceRange::query()->create([
            'ai_common_shared_session_id' => $lease->ai_common_shared_session_id,
            'ai_common_shared_capture_stream_id' => $lease->ai_common_shared_capture_stream_id,
            'relay_lease_id' => $lease->id, 'generation' => $frame->generation,
            'frame_sequence' => $frame->sequence, 'client_event_id' => $frame->clientEventId,
            'start_sample' => $frame->startSample, 'end_sample' => $frame->endSample,
            'sample_rate' => $frame->sampleRate, 'bit_depth' => $frame->bitDepth,
            'channels' => $frame->channels, 'format' => $frame->format,
            'content_sha256' => $frame->contentSha256,
            'authorization_fingerprint' => $lease->audience_fingerprint,
            'consent_fingerprint' => $lease->consent_fingerprint,
            'state' => 'accepted', 'received_at_utc' => now(), 'accepted_at_utc' => now(),
        ]);
    }

    public function sent(AiCommonSharedProviderSession $providerSession, AiCommonSharedSourceRange $range): AiCommonSharedProviderSendRange
    {
        return DB::transaction(fn (): AiCommonSharedProviderSendRange => $this->sentLocked($providerSession, $range), 3);
    }

    /**
     * @param  list<AiCommonSharedSourceRange>  $ranges
     * @return list<AiCommonSharedProviderSendRange>
     */
    public function sentBatch(AiCommonSharedProviderSession $providerSession, array $ranges): array
    {
        if ($ranges === [] || count($ranges) > 10) {
            throw ValidationException::withMessages(['source_range_ids' => 'Realtime sent batch must contain between 1 and 10 ranges.']);
        }

        return DB::transaction(function () use ($providerSession, $ranges): array {
            $lockedProvider = AiCommonSharedProviderSession::query()->lockForUpdate()->findOrFail($providerSession->id);
            foreach ($ranges as $range) {
                if ($lockedProvider->ai_common_shared_capture_stream_id !== $range->ai_common_shared_capture_stream_id
                    || $lockedProvider->generation !== $range->generation || $range->state !== 'accepted') {
                    throw ValidationException::withMessages(['range' => SafeRealtimeReason::ProviderMappingUnverified->value]);
                }
            }
            $rangeIds = array_map(static fn (AiCommonSharedSourceRange $range): int => $range->id, $ranges);
            $existing = AiCommonSharedProviderSendRange::query()->where('provider_session_id', $lockedProvider->id)
                ->whereIn('source_range_id', $rangeIds)->lockForUpdate()->get()->keyBy('source_range_id');
            $nextOrdinal = (int) AiCommonSharedProviderSendRange::query()->where('provider_session_id', $lockedProvider->id)->max('send_ordinal') + 1;
            $nextOffset = (int) (AiCommonSharedProviderSendRange::query()->where('provider_session_id', $lockedProvider->id)->max('provider_offset_end_sample') ?? 0);
            $sent = [];
            foreach ($ranges as $range) {
                $row = $existing->get($range->id);
                if (! $row) {
                    $row = AiCommonSharedProviderSendRange::query()->create([
                        'provider_session_id' => $lockedProvider->id, 'source_range_id' => $range->id,
                        'send_ordinal' => $nextOrdinal,
                        'provider_offset_start_sample' => $nextOffset,
                        'provider_offset_end_sample' => $nextOffset + ($range->end_sample - $range->start_sample),
                        'state' => 'sent', 'sent_at_utc' => now(),
                    ]);
                    $nextOrdinal += 1;
                    $nextOffset = $row->provider_offset_end_sample;
                    $existing->put($range->id, $row);
                }
                $sent[] = $row;
            }

            return $sent;
        }, 3);
    }

    private function sentLocked(AiCommonSharedProviderSession $providerSession, AiCommonSharedSourceRange $range): AiCommonSharedProviderSendRange
    {
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
    }

    public function sourceMapping(AiCommonSharedProviderSession $providerSession, int $providerStart, int $providerEnd): array
    {
        if ($providerStart < 0 || $providerEnd <= $providerStart) {
            return ['verification_state' => 'unverified'];
        }
        $rows = AiCommonSharedProviderSendRange::query()->where('provider_session_id', $providerSession->id)
            ->where('provider_offset_end_sample', '>', $providerStart)
            ->where('provider_offset_start_sample', '<', $providerEnd)
            ->with('sourceRange')->orderBy('send_ordinal')->get();
        if ($rows->isEmpty() || $rows->first()->provider_offset_start_sample > $providerStart
            || $rows->last()->provider_offset_end_sample < $providerEnd) {
            return ['verification_state' => 'unverified'];
        }
        foreach ($rows as $index => $row) {
            if (! $row->sourceRange || $row->state !== 'sent' || $row->sourceRange->state !== 'accepted') {
                return ['verification_state' => 'unverified'];
            }
            if ($index > 0 && ($rows[$index - 1]->provider_offset_end_sample !== $row->provider_offset_start_sample
                || $rows[$index - 1]->sourceRange->end_sample !== $row->sourceRange->start_sample)) {
                return ['verification_state' => 'unverified'];
            }
        }
        $first = $rows->first();
        $last = $rows->last();
        $sourceStart = $first->sourceRange->start_sample + ($providerStart - $first->provider_offset_start_sample);
        $sourceEnd = $last->sourceRange->end_sample - ($last->provider_offset_end_sample - $providerEnd);

        return $sourceEnd > $sourceStart
            ? ['verification_state' => 'verified', 'start_sample' => $sourceStart, 'end_sample' => $sourceEnd]
            : ['verification_state' => 'unverified'];
    }
}
