<?php

namespace App\Contracts;

interface AiCommonAttachmentExtractor
{
    /** @return array{content:string,selector:array,driver:string,version:string} */
    public function extract(string $binary, string $extension, array $selector): array;
}
