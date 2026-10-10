<?php

namespace Tests\Feature;

use App\Services\AiCommon\OpenAiCommonProvider;
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

    private function reply(): array
    {
        return ['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"answer":"Hello","citations":[]}']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20]];
    }
}
