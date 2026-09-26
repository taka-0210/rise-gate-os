<?php

namespace App\Contracts;

use Illuminate\Http\Client\PendingRequest;

interface AiProviderTransport
{
    public function request(string $purpose, int $timeoutSeconds): PendingRequest;
}
