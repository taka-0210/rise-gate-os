<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiUsageLedger;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonUsageRecorder
{
    private const RESULTS = ['success', 'failed', 'unknown', 'discarded'];

    private const UNITS = ['audio_tokens', 'duration_seconds'];

    public function recordTranscription(
        Organization $organization,
        AiCommonConversation $conversation,
        User $actor,
        string $operationId,
        string $logicalRequestId,
        string $provider,
        ?string $model,
        string $result,
        ?string $safeErrorCode,
        ?int $latencyMs,
        ?int $mediaDurationMs,
        array $providerUsage = [],
    ): AiUsageLedger {
        if (! Str::isUuid($operationId) || ! in_array($result, self::RESULTS, true)) {
            throw ValidationException::withMessages(['usage' => 'Invalid transcription usage evidence.']);
        }

        $unit = isset($providerUsage['unit']) && in_array($providerUsage['unit'], self::UNITS, true)
            ? $providerUsage['unit']
            : null;
        $quantity = isset($providerUsage['quantity']) && is_numeric($providerUsage['quantity'])
            ? (string) $providerUsage['quantity']
            : null;
        $cost = isset($providerUsage['estimated_cost_microunits']) && is_int($providerUsage['estimated_cost_microunits'])
            ? $providerUsage['estimated_cost_microunits']
            : null;

        $existing = AiUsageLedger::query()
            ->where('application_operation_id', $operationId)
            ->where('purpose', 'transcription')
            ->where('attempt', 1)
            ->first();
        if ($existing) {
            if ($existing->logical_request_id !== $logicalRequestId || $existing->result !== $result) {
                throw ValidationException::withMessages(['operation_id' => 'The usage operation is already final with different evidence.']);
            }

            return $existing;
        }

        return AiUsageLedger::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $actor->id,
            'ai_common_conversation_id' => $conversation->id,
            'logical_request_id' => $logicalRequestId,
            'application_operation_id' => $operationId,
            'purpose' => 'transcription',
            'provider' => $provider,
            'model' => $model,
            'attempt' => 1,
            'usage_unit' => $unit,
            'usage_quantity' => $quantity,
            'media_duration_ms' => $mediaDurationMs,
            'estimated_cost_microunits' => $cost,
            'price_version' => $cost === null ? null : ($providerUsage['price_version'] ?? null),
            'currency' => $cost === null ? null : ($providerUsage['currency'] ?? null),
            'result' => $result,
            'safe_error_code' => $safeErrorCode,
            'latency_ms' => $latencyMs,
        ]);
    }
}
