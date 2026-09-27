<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonTranscriptionProvider;
use App\Contracts\AiProviderTransport;
use Throwable;

class OpenAiCommonTranscriptionProvider implements AiCommonTranscriptionProvider
{
    public function __construct(private readonly AiProviderTransport $transport) {}

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        $model = (string) config('services.openai.transcription_model', 'gpt-4o-mini-transcribe');
        try {
            $response = $this->transport->request(
                'transcription',
                (int) config('services.ai_common.transcription_timeout_seconds', 60),
            )->attach('file', $binary, 'voice.'.$extension, ['Content-Type' => $mimeType])
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);
        } catch (Throwable $error) {
            throw new AiCommonTranscriptionException('transcription_result_unknown', true, $error);
        }
        if (! $response->successful()) {
            throw new AiCommonTranscriptionException('transcription_provider_error');
        }
        $text = trim((string) $response->json('text'));
        if ($text === '' || mb_strlen($text) > 4000) {
            throw new AiCommonTranscriptionException('transcription_invalid_response');
        }

        return ['text' => $text, 'provider' => 'openai', 'model' => $model];
    }
}
