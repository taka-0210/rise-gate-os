<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonProvider;
use App\Contracts\AiProviderTransport;
use RuntimeException;

class OpenAiCommonProvider implements AiCommonProvider
{
    public function __construct(private readonly AiProviderTransport $transport) {}

    public function respond(array $messages, array $sources): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('provider_unavailable');
        }
        $model = (string) config('services.openai.chat_model');
        $system = 'You are Company OS business_common. Use only the supplied source handles. '
            .'Never call tools or infer internal IDs. Return JSON with answer and citations (source handles only).';
        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'system', 'content' => json_encode(['sources' => $sources], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ...$messages,
            ],
            'response_format' => ['type' => 'json_object'],
        ];
        $response = $this->transport->request('business_common', (int) config('services.ai_common.timeout_seconds', 30))
            ->post('https://api.openai.com/v1/chat/completions', $payload);
        if (! $response->successful()) {
            throw new RuntimeException('provider_error');
        }
        $decoded = json_decode((string) $response->json('choices.0.message.content'), true);
        if (! is_array($decoded) || ! is_string($decoded['answer'] ?? null)) {
            throw new RuntimeException('provider_invalid_response');
        }

        return [
            'answer' => trim($decoded['answer']),
            'citations' => array_values(array_filter($decoded['citations'] ?? [], 'is_string')),
            'provider' => 'openai',
            'model' => $model,
            'input_tokens' => is_numeric($response->json('usage.prompt_tokens')) ? (int) $response->json('usage.prompt_tokens') : null,
            'output_tokens' => is_numeric($response->json('usage.completion_tokens')) ? (int) $response->json('usage.completion_tokens') : null,
        ];
    }
}
