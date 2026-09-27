<?php

declare(strict_types=1);

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonProvider;
use App\Contracts\AiCommonTranscriptionProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$public = realpath(dirname(__DIR__, 2).'/public');
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$candidate = realpath($public.DIRECTORY_SEPARATOR.ltrim($uriPath, '/'));
if ($candidate !== false && str_starts_with($candidate, $public.DIRECTORY_SEPARATOR) && is_file($candidate)) {
    $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $contentType = match ($extension) {
        'css' => 'text/css; charset=UTF-8',
        'js' => 'application/javascript; charset=UTF-8',
        'json', 'webmanifest' => 'application/manifest+json; charset=UTF-8',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        default => 'application/octet-stream',
    };
    header('Content-Type: '.$contentType);
    header('Content-Length: '.filesize($candidate));
    readfile($candidate);

    return true;
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$app->instance(AiCommonAudioInspector::class, new class implements AiCommonAudioInspector
{
    public function inspect(string $binary, string $extension, string $mimeType): array
    {
        $codec = match ($extension) {
            'webm' => 'opus',
            'm4a', 'mp4' => 'aac',
            'mp3' => 'mp3',
            'wav' => 'pcm_s16le',
            default => throw new RuntimeException('unsupported_audio_fixture'),
        };

        return [
            'mime_type' => $mimeType,
            'extension' => $extension,
            'codec' => $codec,
            'duration_ms' => 8_000,
        ];
    }
});
$app->instance(AiCommonTranscriptionProvider::class, new class implements AiCommonTranscriptionProvider
{
    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        return [
            'text' => 'Speaker A proposes the bounded plan. Speaker B raises a different view.',
            'segments' => [
                ['speaker' => 'Speaker A', 'text' => 'Speaker A proposes the bounded plan.', 'start_ms' => 0, 'end_ms' => 3_500, 'confidence' => .93],
                ['speaker' => 'Speaker B', 'text' => 'Speaker B raises a different view.', 'start_ms' => 3_500, 'end_ms' => 8_000, 'confidence' => .86],
            ],
            'provider' => 'p5-synthetic-provider',
            'model' => 'p5-synthetic-diarization-v1',
            'usage' => ['unit' => 'duration_seconds', 'quantity' => '8', 'estimated_cost_microunits' => null],
        ];
    }
});
$app->instance(AiCommonProvider::class, new class implements AiCommonProvider
{
    public function respond(array $messages, array $sources): array
    {
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode(' ');
        if (str_contains($system, 'Maintain bounded rolling context')) {
            usleep(2_000_000);
            $answer = json_encode([
                'current_topic' => ['device verification'],
                'main_views' => ['Speaker A proposes the plan', 'Speaker B raises a different view'],
                'agreement_candidates' => [],
                'open_questions' => ['confirm device behavior'],
                'to_confirm' => [],
                'source_refs' => [],
            ], JSON_THROW_ON_ERROR);
        } elseif (str_contains($system, 'bounded candidate lines')) {
            usleep(2_000_000);
            $answer = "unresolved: Confirm device behavior\naction_candidate: Review with a human";
        } else {
            usleep(5_000_000);
            $answer = 'This is the single synthetic Shared CO response for device verification.';
        }

        return [
            'answer' => $answer,
            'citations' => [],
            'provider' => 'p5-synthetic-provider',
            'model' => 'p5-synthetic-chat-v1',
            'input_tokens' => 120,
            'output_tokens' => 24,
        ];
    }
});

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);

