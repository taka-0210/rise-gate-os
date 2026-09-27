<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\User;

class AiCommonTranscriptionEvidence
{
    public function __construct(
        private readonly AiCommonUsageRecorder $usage,
        private readonly AiCommonAuditWriter $audit,
    ) {}

    public function record(
        Organization $organization,
        AiCommonConversation $conversation,
        User $actor,
        string $operationId,
        string $logicalRequestId,
        string $subjectType,
        ?string $subjectPublicId,
        string $provider,
        ?string $model,
        string $result,
        ?string $safeErrorCode,
        ?int $latencyMs,
        ?int $mediaDurationMs,
        array $providerUsage = [],
        bool $providerAttempted = true,
    ): void {
        if ($providerAttempted) {
            $this->usage->recordTranscription(
                $organization,
                $conversation,
                $actor,
                $operationId,
                $logicalRequestId,
                $provider,
                $model,
                $result,
                $safeErrorCode,
                $latencyMs,
                $mediaDurationMs,
                $providerUsage,
            );
        }

        $this->audit->record(
            $organization,
            $conversation,
            $actor,
            $operationId,
            'transcription.finalized',
            $subjectType,
            $subjectPublicId,
            $result,
            $safeErrorCode,
            [
                'purpose' => 'transcription',
                'attempt' => $providerAttempted ? 1 : 0,
                'media_duration_ms' => $mediaDurationMs,
                'usage_unit' => $providerUsage['unit'] ?? null,
                'usage_known' => isset($providerUsage['quantity']),
            ],
        );
    }
}
