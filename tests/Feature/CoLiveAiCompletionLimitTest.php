<?php

namespace Tests\Feature;

use App\Services\AiCommon\AiCommonProviderResponseException;
use App\Services\AiCommon\OpenAiCommonProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CoLiveAiCompletionLimitTest extends TestCase
{
    public function test_temporary_local_diagnostic_is_unreachable(): void
    {
        $this->get('/company/settings/co-local-readiness')->assertNotFound();
    }

    public function test_completion_limit_is_sent_without_changing_context(): void
    {
        config(['services.openai.api_key' => 'synthetic-test-only', 'services.openai.chat_model' => 'gpt-5.6-terra']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response($this->reply())]);
        $result = app(OpenAiCommonProvider::class)->respond([['role' => 'user', 'content' => 'Hello']], []);
        $this->assertSame('Hello', $result['answer']);
        Http::assertSent(fn ($request) => $request['max_completion_tokens'] === 2048
            && ! isset($request['max_tokens'])
            && $request['messages'][2]['content'] === 'Hello');
    }

    public function test_invalid_limits_stop_before_transport(): void
    {
        config(['services.openai.api_key' => 'synthetic-test-only']);
        Http::preventStrayRequests();
        Http::fake();
        foreach ([0, -1, 2049, 'invalid', '1.5'] as $limit) {
            config(['services.ai_common.max_completion_tokens' => $limit]);
            try {
                app(OpenAiCommonProvider::class)->respond([], []);
                $this->fail('Invalid limit was accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('provider_invalid_response', $error->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    public function test_truncated_or_over_limit_response_is_not_published(): void
    {
        config(['services.openai.api_key' => 'synthetic-test-only']);
        Http::preventStrayRequests();
        foreach (['length', 'over_limit'] as $kind) {
            $reply = $this->reply();
            if ($kind === 'length') {
                $reply['choices'][0]['finish_reason'] = 'length';
            } else {
                $reply['usage']['completion_tokens'] = 2049;
            }
            Http::fake(['api.openai.com/*' => Http::response($reply)]);
            try {
                app(OpenAiCommonProvider::class)->respond([], []);
                $this->fail('Unsafe response was accepted');
            } catch (RuntimeException $error) {
                $this->assertSame('provider_invalid_response', $error->getMessage());
            }
        }
    }

    public function test_strict_schema_preserves_selected_context(): void
    {
        config(['services.openai.api_key' => 'synthetic-test-only']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response($this->reply())]);
        $sources = [['handle' => 'synthetic', 'revision_no' => 1, 'content' => 'Synthetic approved policy']];
        app(OpenAiCommonProvider::class)->respond([['role' => 'user', 'content' => 'Synthetic question']], $sources);
        Http::assertSent(fn ($request) => $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['strict'] === true
            && $request['response_format']['json_schema']['schema']['required'] === ['answer', 'citations']
            && $request['response_format']['json_schema']['schema']['additionalProperties'] === false
            && json_decode($request['messages'][1]['content'], true)['sources'] === $sources);
    }

    public function test_invalid_answers_fail_closed_with_metadata_only(): void
    {
        config(['services.openai.api_key' => 'synthetic-test-only']);
        Http::preventStrayRequests();
        foreach (['not-json', '{}', '{"answer":42}', '{"answer":null}', '{"answer":"  "}', '{"answer":"OK","citations":"bad"}'] as $content) {
            $reply = $this->reply();
            $reply['choices'][0]['message']['content'] = $content;
            Http::fake(['api.openai.com/*' => Http::response($reply)]);
            try {
                app(OpenAiCommonProvider::class)->respond([], []);
                $this->fail('Invalid answer accepted');
            } catch (AiCommonProviderResponseException $error) {
                $this->assertSame('provider_invalid_response', $error->getMessage());
                $this->assertSame(200, $error->diagnostic['http_status']);
                $this->assertSame('stop', $error->diagnostic['finish_reason']);
                $this->assertSame(20, $error->diagnostic['output_tokens']);
                $this->assertSame(['http_status', 'finish_reason', 'json_parse_success', 'answer_present', 'answer_type', 'input_tokens', 'output_tokens', 'failure_class'], array_keys($error->diagnostic));
            }
        }
    }

    public function test_http_errors_do_not_expose_provider_error_body(): void
    {
        config(['services.openai.api_key' => 'synthetic-test-only']);
        Http::preventStrayRequests();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'SYNTHETIC_PRIVATE_ERROR']], 429)]);
        try {
            app(OpenAiCommonProvider::class)->respond([], []);
            $this->fail('HTTP error accepted');
        } catch (AiCommonProviderResponseException $error) {
            $this->assertSame('provider_error', $error->getMessage());
            $this->assertSame(429, $error->diagnostic['http_status']);
            $this->assertStringNotContainsString('SYNTHETIC_PRIVATE_ERROR', json_encode($error->diagnostic));
        }
    }

    public function test_timeout_is_sanitized_without_raw_exception(): void
    {
        config(['services.openai.api_key' => 'synthetic-test-only']);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('SYNTHETIC_PRIVATE_TRANSPORT'));
        try {
            app(OpenAiCommonProvider::class)->respond([], []);
            $this->fail('Timeout accepted');
        } catch (AiCommonProviderResponseException $error) {
            $this->assertSame('timeout_or_connection', $error->diagnostic['failure_class']);
            $this->assertNull($error->diagnostic['http_status']);
            $this->assertNull($error->getPrevious());
        }
    }

    private function reply(): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"answer":"Hello","citations":[]}']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
    }
}
