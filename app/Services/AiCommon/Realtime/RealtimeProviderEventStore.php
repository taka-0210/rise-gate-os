<?php

namespace App\Services\AiCommon\Realtime;

use App\Models\AiCommonSharedProviderEventReceipt;
use App\Models\AiCommonSharedProviderSession;
use App\Models\AiCommonSharedRelayLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RealtimeProviderEventStore
{
    public function persist(ProviderStreamingEventEnvelope $event, AiCommonSharedProviderSession $session): ?AiCommonSharedProviderEventReceipt
    {
        if ($event->eventType === ProviderStreamingEventEnvelope::PARTIAL) {
            return null;
        }
        $payload = $event->durableReceipt();

        return DB::transaction(function () use ($event, $session, $payload): AiCommonSharedProviderEventReceipt {
            $locked = AiCommonSharedProviderSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($event->eventIdentityHash) {
                $existing = AiCommonSharedProviderEventReceipt::query()->where('provider_session_id', $locked->id)
                    ->where('provider_event_identity_hash', $event->eventIdentityHash)->first();
                if ($existing) {
                    return $existing;
                }
            }
            if ($event->receiveOrder <= $locked->receive_order) {
                throw ValidationException::withMessages(['provider_event' => SafeRealtimeReason::ProviderEventLate->value]);
            }
            $lease = AiCommonSharedRelayLease::query()->lockForUpdate()->findOrFail($locked->relay_lease_id);
            $locked->update(['receive_order' => $event->receiveOrder]);
            $usage = $payload['usage'];
            $unverifiedFinal = $event->eventType === ProviderStreamingEventEnvelope::FINAL && $event->rangeVerificationState !== 'verified';
            $lateFinal = $event->eventType === ProviderStreamingEventEnvelope::FINAL
                && ($lease->state !== AiCommonSharedRelayLease::STATE_ACTIVE || $lease->generation !== $locked->generation);
            $rejectionReason = $unverifiedFinal
                ? SafeRealtimeReason::ProviderMappingUnverified
                : ($lateFinal ? SafeRealtimeReason::ProviderEventLate : null);

            return AiCommonSharedProviderEventReceipt::query()->create([
                'provider_session_id' => $locked->id,
                'provider_event_identity_hash' => $payload['provider_event_identity_hash'],
                'provider_sequence' => $payload['provider_sequence'],
                'receive_order' => $payload['receive_order'],
                'normalized_event_type' => $payload['normalized_event_type'],
                'provider_start_sample' => $payload['provider_start_sample'],
                'provider_duration_samples' => $payload['provider_duration_samples'],
                'verified_source_start_sample' => $payload['verified_source_start_sample'],
                'verified_source_end_sample' => $payload['verified_source_end_sample'],
                'range_verification_state' => $payload['range_verification_state'],
                'final_content_sha256' => $payload['final_content_sha256'],
                'normalized_final_metadata' => $payload['normalized_final_metadata'],
                'status' => $rejectionReason ? 'rejected' : 'accepted',
                'safe_reason_code' => $rejectionReason?->value ?? $payload['safe_reason_code'],
                'received_at_utc' => now(),
                'finalized_at_utc' => $event->eventType === ProviderStreamingEventEnvelope::FINAL ? now() : null,
                'rejected_at_utc' => $rejectionReason ? now() : null,
                'usage_quantity' => $usage['quantity'] ?? null,
                'usage_unit' => $usage['unit'] ?? null,
                'price_version' => $usage['price_version'] ?? null,
                'estimated_cost_microunits' => $usage['estimated_cost_microunits'] ?? null,
            ]);
        }, 3);
    }
}
