<?php

namespace App\Contracts;

interface AiCommonAttachmentInspector
{
    /**
     * @param  array{extension:string,mime_type:string,size_bytes:int,variant:string}  $metadata
     * @return array{driver:string,version:string}
     */
    public function inspect(string $binary, array $metadata): array;
}
