<?php

namespace App\Contracts;

interface AiCommonTranscriptionProvider
{
    /**
     * @return array{
     *   text:string,
     *   provider:string,
     *   model:?string,
     *   segments?:list<array{text:string,start_ms?:int,end_ms?:int,speaker_label?:string,confidence?:int|float|string}>,
     *   usage?:array{unit?:string,quantity?:int|float|string,estimated_cost_microunits?:int,price_version?:string,currency?:string}
     * }
     */
    public function transcribe(string $binary, string $mimeType, string $extension): array;
}
