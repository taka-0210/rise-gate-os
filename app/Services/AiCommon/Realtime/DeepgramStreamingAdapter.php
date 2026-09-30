<?php

namespace App\Services\AiCommon\Realtime;

final class DeepgramStreamingAdapter implements ProviderStreamingPort
{
    public function normalize(array $providerEvent, string $providerSessionId, int $receiveOrder, array $sourceMapping): ProviderStreamingEventEnvelope
    {
        $type = (string) ($providerEvent['type'] ?? '');
        $alternative = $providerEvent['channel']['alternatives'][0] ?? [];
        $content = isset($alternative['transcript']) ? trim((string) $alternative['transcript']) : null;
        $hasDurableContent = is_string($content) && $content !== '';
        $eventType = match (true) {
            $type === 'Results' && ($providerEvent['is_final'] ?? false) === true && $hasDurableContent => ProviderStreamingEventEnvelope::FINAL,
            $type === 'Results' && ($providerEvent['is_final'] ?? false) === true => ProviderStreamingEventEnvelope::METADATA,
            $type === 'Results' => ProviderStreamingEventEnvelope::PARTIAL,
            in_array($type, ['Metadata', 'UtteranceEnd', 'SpeechStarted'], true) => ProviderStreamingEventEnvelope::METADATA,
            $type === 'Error' => ProviderStreamingEventEnvelope::ERROR,
            $type === 'Close' => ProviderStreamingEventEnvelope::CLOSE,
            default => ProviderStreamingEventEnvelope::ERROR,
        };
        $words = collect($alternative['words'] ?? [])->map(fn (array $word): array => [
            'start_ms' => isset($word['start']) ? (int) round(((float) $word['start']) * 1000) : null,
            'end_ms' => isset($word['end']) ? (int) round(((float) $word['end']) * 1000) : null,
            'confidence' => isset($word['confidence']) ? (float) $word['confidence'] : null,
            'speaker' => isset($word['speaker']) ? 'speaker-'.(int) $word['speaker'] : null,
        ])->all();
        $speakers = collect($words)->pluck('speaker')->filter()->unique()->values()->all();
        $requestIdentity = $providerEvent['request_id'] ?? $providerEvent['event_id'] ?? $providerEvent['metadata']['request_id'] ?? null;
        $identity = $requestIdentity === null ? null : hash('sha256', implode('|', [
            (string) $requestIdentity, $type, ($providerEvent['is_final'] ?? false) === true ? 'final' : 'interim',
            (string) ($providerEvent['start'] ?? ''), (string) ($providerEvent['duration'] ?? ''),
            hash('sha256', (string) ($content ?? '')),
        ]));
        $verified = ($sourceMapping['verification_state'] ?? 'unverified') === 'verified';

        return new ProviderStreamingEventEnvelope(
            'deepgram',
            (string) config('ai-common-realtime.deepgram.adapter_version'),
            $providerSessionId,
            $eventType,
            $identity,
            isset($providerEvent['sequence']) ? (int) $providerEvent['sequence'] : null,
            $receiveOrder,
            in_array($eventType, [ProviderStreamingEventEnvelope::PARTIAL, ProviderStreamingEventEnvelope::FINAL], true) ? $content : null,
            $eventType === ProviderStreamingEventEnvelope::FINAL && $content !== null ? hash('sha256', $content) : null,
            $verified ? (int) $sourceMapping['start_sample'] : null,
            $verified ? (int) $sourceMapping['end_sample'] : null,
            $verified ? 'verified' : 'unverified',
            $words,
            $speakers,
            $eventType === ProviderStreamingEventEnvelope::ERROR ? SafeRealtimeReason::ProviderUnavailable->value : null,
            is_array($providerEvent['usage'] ?? null) ? $providerEvent['usage'] : [],
            isset($providerEvent['start']) ? (int) round(((float) $providerEvent['start']) * 16000) : null,
            isset($providerEvent['duration']) ? (int) round(((float) $providerEvent['duration']) * 16000) : null,
        );
    }

    public function requestProfile(): array
    {
        return [
            'model' => config('ai-common-realtime.deepgram.model'),
            'language' => config('ai-common-realtime.deepgram.language'),
            'encoding' => 'linear16',
            'sample_rate' => config('ai-common-realtime.canonical_audio.sample_rate'),
            'channels' => config('ai-common-realtime.canonical_audio.channels'),
            'diarize' => config('ai-common-realtime.deepgram.diarize'),
            'interim_results' => config('ai-common-realtime.deepgram.interim_results'),
            'mip_opt_out' => config('ai-common-realtime.deepgram.mip_opt_out'),
            'automatic_retry' => false,
        ];
    }
}
