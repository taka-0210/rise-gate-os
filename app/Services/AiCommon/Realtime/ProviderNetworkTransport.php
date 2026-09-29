<?php

namespace App\Services\AiCommon\Realtime;

interface ProviderNetworkTransport
{
    public function open(array $request): mixed;
}
