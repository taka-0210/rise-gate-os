<?php

namespace App\Services\AiCommon\Realtime;

use LogicException;

final readonly class ProviderStreamingEventEnvelope
{
    public const PARTIAL = 'partial';

    public const FINAL = 'final';

    public const METADATA = 'metadata';

    public const ERROR = 'error';

    public const CLOSE = 'close';

    public function __construct(
        public string $adapter,
        public string $adapterVersion,
        public string $providerSessionId,
        public string $eventType,
        public ?string $eventIdentityHash,
        public ?int $providerSequence,
        public int $receiveOrder,
        public ?string $content,
        public ?string $contentSha256,
        public ?int $sourceStartSample,
        public ?int $sourceEndSample,
        public string $rangeVerificationState,
        public array $wordTiming,
        public array $anonymousSpeakers,
        public ?string $safeReasonCode = null,
        public array $usage = [],
        public ?int $providerStartSample = null,
        public ?int $providerDurationSamples = null,
    ) {
        if (! in_array($eventType, [self::PARTIAL, self::FINAL, self::METADATA, self::ERROR, self::CLOSE], true)
            || $receiveOrder < 1
            || ($eventType === self::FINAL && ($content === null || $contentSha256 === null))
            || ($eventType === self::PARTIAL && $contentSha256 !== null)) {
            throw new LogicException('Invalid provider-neutral streaming envelope.');
        }
    }

    public function durableReceipt(): array
    {
        if ($this->eventType === self::PARTIAL) {
            throw new LogicException('Partial events are ephemeral and cannot become durable receipts.');
        }

        return [
            'provider_event_identity_hash' => $this->eventIdentityHash,
            'provider_sequence' => $this->providerSequence,
            'receive_order' => $this->receiveOrder,
            'provider_start_sample' => $this->providerStartSample,
            'provider_duration_samples' => $this->providerDurationSamples,
            'normalized_event_type' => $this->eventType,
            'verified_source_start_sample' => $this->sourceStartSample,
            'verified_source_end_sample' => $this->sourceEndSample,
            'range_verification_state' => $this->rangeVerificationState,
            'final_content_sha256' => $this->contentSha256,
            'normalized_final_metadata' => $this->eventType === self::FINAL ? [
                'content' => $this->content,
                'words' => $this->wordTiming,
                'speakers' => $this->anonymousSpeakers,
            ] : null,
            'usage' => $this->usage,
            'safe_reason_code' => $this->safeReasonCode,
        ];
    }
}
