<?php

declare(strict_types=1);

use App\Contracts\AiCommonAudioInspector;
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
            'duration_ms' => 2000,
        ];
    }
});
$app->instance(AiCommonTranscriptionProvider::class, new class implements AiCommonTranscriptionProvider
{
    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        return [
            'text' => 'Synthetic device transcript for explicit human confirmation.',
            'provider' => 'p5-synthetic-provider',
            'model' => 'p5-synthetic-transcription-v1',
            'usage' => [],
        ];
    }
});

$request = Request::capture();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
