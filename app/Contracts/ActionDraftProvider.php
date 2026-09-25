<?php

namespace App\Contracts;

interface ActionDraftProvider
{
    /** @param array{target:string,action_title:string,done_condition:string,instruction:string} $payload */
    public function suggest(array $payload): string;
}
