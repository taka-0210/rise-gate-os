<?php

namespace App\Contracts;

interface AiCommonTranscriptionProvider
{
    /** @return array{text:string,provider:string,model:?string,usage?:array{unit?:string,quantity?:int|float|string,estimated_cost_microunits?:int,price_version?:string,currency?:string}} */
    public function transcribe(string $binary, string $mimeType, string $extension): array;
}
