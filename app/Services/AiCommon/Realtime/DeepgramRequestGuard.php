<?php

namespace App\Services\AiCommon\Realtime;

use Illuminate\Validation\ValidationException;

final class DeepgramRequestGuard
{
    public function open(array $request, ProviderNetworkTransport $transport): mixed
    {
        if (! config('ai-common-realtime.enabled') || ! config('ai-common-realtime.audio_send_enabled')) {
            throw ValidationException::withMessages(['provider' => SafeRealtimeReason::AudioSendDisabled->value]);
        }
        if (($request['mip_opt_out'] ?? null) !== true
            || config('ai-common-realtime.deepgram.mip_opt_out') !== true
            || ($request['automatic_retry'] ?? null) !== false
            || config('ai-common-realtime.deepgram.automatic_retry') !== false) {
            throw ValidationException::withMessages(['provider' => SafeRealtimeReason::MipGuardFailed->value]);
        }
        foreach (['api_key', 'authorization', 'credential', 'secret'] as $forbidden) {
            if (array_key_exists($forbidden, $request)) {
                throw ValidationException::withMessages(['provider' => 'provider_secret_crossed_request_boundary']);
            }
        }

        return $transport->open($request);
    }
}
