<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonProvider;
use App\Contracts\AiProviderTransport;
use Illuminate\Http\Client\ConnectionException;
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
        $limit = filter_var(config('services.ai_common.max_completion_tokens', 2048), FILTER_VALIDATE_INT);
        if ($model === '' || $limit === false || $limit < 1 || $limit > 2048) {
            throw new RuntimeException('provider_invalid_response');
        }
        $system = 'You are Company OS business_common. Use only the supplied source handles. '
            .'Never call tools or infer internal IDs. Return JSON with answer and citations (source handles only).';
        $payload = [
            'model' => $model,
            // Includes reasoning tokens; not a bound on input tokens or total API cost.
            'max_completion_tokens' => $limit,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'system', 'content' => json_encode(['sources' => $sources], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ...$messages,
            ],
            'response_format' => ['type' => 'json_schema', 'json_schema' => [
                'name' => 'co_answer', 'strict' => true,
                'schema' => ['type' => 'object', 'properties' => [
                    'answer' => ['type' => 'string'],
                    'citations' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], 'required' => ['answer', 'citations'], 'additionalProperties' => false],
            ]],
        ];
        try {
            $response = $this->transport->request('business_common', (int) config('services.ai_common.timeout_seconds', 30))
                ->post('https://api.openai.com/v1/chat/completions', $payload);
        } catch (ConnectionException) {
            throw new AiCommonProviderResponseException('provider_error', [
                'http_status' => null, 'finish_reason' => 'unknown', 'json_parse_success' => null,
                'answer_present' => null, 'answer_type' => 'unknown', 'input_tokens' => null,
                'output_tokens' => null, 'failure_class' => 'timeout_or_connection',
            ]);
        }
        $content = $response->json('choices.0.message.content');
        $decoded = is_string($content) ? json_decode($content, true) : null;
        $jsonValid = is_string($content) && json_last_error() === JSON_ERROR_NONE;
        $finish = $response->json('choices.0.finish_reason');
        $diagnostic = [
            'http_status' => $response->status(),
            'finish_reason' => in_array($finish, ['stop', 'length', 'content_filter', 'tool_calls', 'function_call'], true) ? $finish : 'unknown',
            'json_parse_success' => $jsonValid,
            'answer_present' => is_array($decoded) && array_key_exists('answer', $decoded),
            'answer_type' => is_array($decoded) && array_key_exists('answer', $decoded) ? get_debug_type($decoded['answer']) : 'missing',
            'input_tokens' => is_numeric($response->json('usage.prompt_tokens')) ? (int) $response->json('usage.prompt_tokens') : null,
            'output_tokens' => is_numeric($response->json('usage.completion_tokens')) ? (int) $response->json('usage.completion_tokens') : null,
        ];
        if (! $response->successful()) {
            throw new AiCommonProviderResponseException('provider_error', $diagnostic + ['failure_class' => 'http_error']);
        }
        if ($response->json('choices.0.finish_reason') === 'length'
            || (is_numeric($response->json('usage.completion_tokens'))
                && (int) $response->json('usage.completion_tokens') > $limit)) {
            // Never publish a truncated result or a provider-reported limit violation.
            throw new AiCommonProviderResponseException('provider_invalid_response', $diagnostic + ['failure_class' => 'completion_limit']);
        }
        if (! is_array($decoded) || ! is_string($decoded['answer'] ?? null)) {
            throw new AiCommonProviderResponseException('provider_invalid_response', $diagnostic + ['failure_class' => 'answer_format']);
        }
        if (trim($decoded['answer']) === '') {
            throw new AiCommonProviderResponseException('provider_invalid_response', $diagnostic + ['failure_class' => 'empty_answer']);
        }
        if (array_key_exists('citations', $decoded) && (! is_array($decoded['citations']) || count(array_filter($decoded['citations'], 'is_string')) !== count($decoded['citations']))) {
            throw new AiCommonProviderResponseException('provider_invalid_response', $diagnostic + ['failure_class' => 'citations_format']);
        }

        return [
            'answer' => trim($decoded['answer']),
            '_diagnostic' => $diagnostic + ['failure_class' => null],
            'citations' => array_values(array_filter($decoded['citations'] ?? [], 'is_string')),
            'provider' => 'openai',
            'model' => $model,
            'input_tokens' => is_numeric($response->json('usage.prompt_tokens')) ? (int) $response->json('usage.prompt_tokens') : null,
            'output_tokens' => is_numeric($response->json('usage.completion_tokens')) ? (int) $response->json('usage.completion_tokens') : null,
        ];
    }
}
