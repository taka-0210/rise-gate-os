<?php

namespace App\Contracts;

interface AiCommonTranscriptionProvider
{
    /** @return array{text:string,provider:string,model:?string} */
    public function transcribe(string $binary, string $mimeType, string $extension): array;
}
