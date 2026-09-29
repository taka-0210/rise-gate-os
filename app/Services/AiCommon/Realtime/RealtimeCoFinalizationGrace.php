<?php

namespace App\Services\AiCommon\Realtime;

use App\Models\AiCommonSharedDurableFinalCommit;
use App\Models\AiCommonSharedProviderEventReceipt;
use App\Models\AiCommonSharedProviderSession;
use App\Models\AiCommonSharedSession;
use Illuminate\Validation\ValidationException;

final class RealtimeCoFinalizationGrace
{
    public function awaitTarget(AiCommonSharedSession $session, ?string $providerSessionPublicId, ?int $receiveOrder): array
    {
        if ($providerSessionPublicId === null && $receiveOrder === null) {
            return [];
        }
        if ($providerSessionPublicId === null || $receiveOrder === null || $receiveOrder < 1) {
            throw ValidationException::withMessages(['realtime_target' => 'A complete realtime partial target is required.']);
        }
        $graceMs = (int) config('ai-common-realtime.finalization_grace_ms');
        if ($graceMs < 0 || $graceMs > 2000) {
            throw ValidationException::withMessages(['realtime_target' => 'Unsafe CO finalization grace configuration.']);
        }
        $deadline = hrtime(true) + ($graceMs * 1_000_000);
        do {
            $provider = AiCommonSharedProviderSession::query()
                ->where('public_id', $providerSessionPublicId)
                ->whereHas('captureStream', fn ($query) => $query->where('ai_common_shared_session_id', $session->id))
                ->first();
            if ($provider) {
                $receipt = AiCommonSharedProviderEventReceipt::query()
                    ->where('provider_session_id', $provider->id)
                    ->where('receive_order', $receiveOrder)
                    ->where('normalized_event_type', 'final')->where('status', 'accepted')->first();
                $commit = $receipt ? AiCommonSharedDurableFinalCommit::query()
                    ->where('provider_event_receipt_id', $receipt->id)->where('state', 'committed')->first() : null;
                if ($commit) {
                    return $commit->items()->orderBy('ordinal')->pluck('transcript_revision_id')->map(fn ($id): int => (int) $id)->all();
                }
            }
            if (hrtime(true) >= $deadline) {
                return [];
            }
            usleep(min(50_000, max(1, (int) (($deadline - hrtime(true)) / 1000))));
        } while (true);
    }
}
