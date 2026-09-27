<?php

namespace App\Contracts;

interface AiCommonAudioInspector
{
    /** @return array{mime_type:string,extension:string,codec:string,duration_ms:int} */
    public function inspect(string $binary, string $extension, string $mimeType): array;
}
