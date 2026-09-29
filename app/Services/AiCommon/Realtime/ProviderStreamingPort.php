<?php

namespace App\Services\AiCommon\Realtime;

interface ProviderStreamingPort
{
    public function normalize(array $providerEvent, string $providerSessionId, int $receiveOrder, array $sourceMapping): ProviderStreamingEventEnvelope;
}
