<?php

namespace App\Services\AiCommon;

use App\Contracts\AiProviderTransport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class OpenAiProviderTransport implements AiProviderTransport
{
    private const PURPOSES = ['business_common', 'project_chat', 'project_image', 'action_draft'];

    public function request(string $purpose, int $timeoutSeconds): PendingRequest
    {
        if (! in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Unsupported AI purpose.');
        }
        $key = (string) config('services.openai.api_key');
        if ($key === '') {
            throw new RuntimeException('AI provider is not configured.');
        }

        return Http::withToken($key)->acceptJson()->timeout($timeoutSeconds);
    }
}
