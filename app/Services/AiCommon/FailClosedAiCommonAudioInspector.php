<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonAudioInspector;

class FailClosedAiCommonAudioInspector implements AiCommonAudioInspector
{
    public function inspect(string $binary, string $extension, string $mimeType): array
    {
        throw new AiCommonInspectionUnavailable('audio_inspector_unavailable');
    }
}
