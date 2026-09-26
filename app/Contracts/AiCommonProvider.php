<?php

namespace App\Contracts;

interface AiCommonProvider
{
    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<int, array{handle:string,type:string,version:string,data:array}> $sources
     * @return array{answer:string,citations:array<int,string>,provider:string,model:?string,input_tokens:?int,output_tokens:?int}
     */
    public function respond(array $messages, array $sources): array;
}
