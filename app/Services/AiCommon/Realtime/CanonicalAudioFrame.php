<?php

namespace App\Services\AiCommon\Realtime;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

final readonly class CanonicalAudioFrame
{
    public function __construct(
        public string $leaseId,
        public string $streamId,
        public int $generation,
        public int $sequence,
        public string $clientEventId,
        public int $startSample,
        public int $endSample,
        public int $sampleCount,
        public int $sampleRate,
        public int $bitDepth,
        public int $channels,
        public string $format,
        public string $contentSha256,
    ) {
        $expected = config('ai-common-realtime.canonical_audio');
        if (! Str::isUuid($leaseId) || ! Str::isUuid($clientEventId)
            || $streamId === '' || $generation < 1 || $sequence < 1
            || $startSample < 0 || $endSample <= $startSample
            || $sampleCount !== $endSample - $startSample
            || $sampleRate !== (int) $expected['sample_rate']
            || $bitDepth !== (int) $expected['bit_depth']
            || $channels !== (int) $expected['channels']
            || $format !== (string) $expected['format']
            || preg_match('/^[a-f0-9]{64}$/', $contentSha256) !== 1) {
            throw ValidationException::withMessages(['frame' => 'Canonical audio frame contract failed closed.']);
        }
    }

    public static function fromArray(array $frame): self
    {
        return new self(
            (string) ($frame['lease_id'] ?? ''),
            (string) ($frame['stream_id'] ?? ''),
            (int) ($frame['generation'] ?? 0),
            (int) ($frame['sequence'] ?? 0),
            (string) ($frame['client_event_id'] ?? ''),
            (int) ($frame['start_sample'] ?? -1),
            (int) ($frame['end_sample'] ?? -1),
            (int) ($frame['sample_count'] ?? -1),
            (int) ($frame['sample_rate'] ?? 0),
            (int) ($frame['bit_depth'] ?? 0),
            (int) ($frame['channels'] ?? 0),
            (string) ($frame['format'] ?? ''),
            strtolower((string) ($frame['content_sha256'] ?? '')),
        );
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
