<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonProvider;
use App\Models\AiCommonConversation;
use App\Models\AiUsageLedger;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

class AiCommonGateway
{
    public const PURPOSE = 'business_common';

    public function __construct(private readonly AiCommonProvider $provider) {}

    public function respond(User $actor, AiCommonConversation $conversation, array $messages, array $sources, ?string $logicalRequestId = null): array
    {
        $requestId = $logicalRequestId ?: (string) Str::uuid();
        $started = hrtime(true);
        try {
            $result = $this->provider->respond($messages, $sources);
            $this->record($actor, $conversation, $requestId, $result, 'success', null, $started);

            return $result + ['logical_request_id' => $requestId];
        } catch (Throwable $error) {
            $code = in_array($error->getMessage(), ['provider_unavailable', 'provider_error', 'provider_invalid_response'], true)
                ? $error->getMessage() : 'provider_failure';
            $this->record($actor, $conversation, $requestId, [
                'provider' => 'openai', 'model' => null, 'input_tokens' => null, 'output_tokens' => null,
            ], 'failed', $code, $started);
            throw new AiCommonGatewayException($code, previous: $error);
        }
    }

    private function record(User $actor, AiCommonConversation $conversation, string $requestId, array $result, string $status, ?string $error, int $started): void
    {
        $input = $result['input_tokens'] ?? null;
        $output = $result['output_tokens'] ?? null;
        $cost = null;
        if ($input !== null && $output !== null) {
            $cost = (int) round(
                ($input * (float) config('services.openai.input_usd_per_million')
                + $output * (float) config('services.openai.output_usd_per_million'))
                * 1_000_000 / 1_000_000
            );
        }
        AiUsageLedger::query()->create([
            'organization_id' => $conversation->organization_id,
            'user_id' => $actor->id,
            'ai_common_conversation_id' => $conversation->id,
            'logical_request_id' => $requestId,
            'purpose' => self::PURPOSE,
            'provider' => $result['provider'] ?? 'openai',
            'model' => $result['model'] ?? null,
            'attempt' => 1,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'estimated_cost_microunits' => $cost,
            'price_version' => $cost === null ? null : (string) config('services.ai_common.price_version', 'configured-2026-09'),
            'currency' => $cost === null ? null : 'USD',
            'result' => $status,
            'safe_error_code' => $error,
            'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
        ]);
    }
}
