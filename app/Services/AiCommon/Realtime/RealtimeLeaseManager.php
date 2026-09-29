<?php

namespace App\Services\AiCommon\Realtime;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedCaptureStream;
use App\Models\AiCommonSharedRelayControlEvent;
use App\Models\AiCommonSharedRelayLease;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\Organization;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedSessionAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RealtimeLeaseManager
{
    public function __construct(private readonly AiCommonSharedSessionAccess $access) {}

    public function issue(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedCaptureStream $stream): AiCommonSharedRelayLease
    {
        if (! config('ai-common-realtime.enabled')) {
            throw ValidationException::withMessages(['lease' => SafeRealtimeReason::AudioSendDisabled->value]);
        }
        $ttl = $this->ttl();

        return DB::transaction(function () use ($actor, $organization, $conversation, $session, $stream, $ttl): AiCommonSharedRelayLease {
            $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $participant = $this->access->authorize($actor, $organization, $conversation, $lockedSession, true);
            $roster = $this->access->assertConsents($actor, $organization, $conversation, $lockedSession, [
                AiCommonSharedSessionConsent::PURPOSE_RECORDING,
                AiCommonSharedSessionConsent::PURPOSE_EXTERNAL_ASR,
                AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
            ]);
            $lockedStream = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($stream->id);
            if ($lockedSession->state !== AiCommonSharedSession::STATE_ACTIVE
                || $lockedStream->ai_common_shared_session_id !== $lockedSession->id
                || $lockedStream->operator_session_participant_id !== $participant->id
                || $lockedStream->state !== AiCommonSharedCaptureStream::STATE_RECORDING) {
                throw ValidationException::withMessages(['lease' => 'A current active capture stream is required.']);
            }
            [$audience, $consent, $membership, $credential] = $this->fingerprints($roster);
            $existing = AiCommonSharedRelayLease::query()->where('ai_common_shared_capture_stream_id', $lockedStream->id)
                ->where('generation', $lockedStream->generation)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->state !== AiCommonSharedRelayLease::STATE_ACTIVE
                    || $existing->expires_at_utc->lte(now())
                    || ! hash_equals($existing->audience_fingerprint, $audience)
                    || ! hash_equals($existing->consent_fingerprint, $consent)
                    || ! hash_equals($existing->membership_fingerprint, $membership)
                    || ! hash_equals($existing->credential_fingerprint, $credential)) {
                    throw ValidationException::withMessages(['lease' => 'The existing lease binding is stale. Start a new generation.']);
                }

                return $existing;
            }

            return AiCommonSharedRelayLease::query()->create([
                'organization_id' => $organization->id,
                'ai_common_conversation_id' => $conversation->id,
                'ai_common_shared_session_id' => $lockedSession->id,
                'ai_common_shared_capture_stream_id' => $lockedStream->id,
                'purpose_revision_id' => $lockedSession->purpose_revision_id,
                'generation' => $lockedStream->generation,
                'state' => AiCommonSharedRelayLease::STATE_ACTIVE,
                'audience_fingerprint' => $audience,
                'consent_fingerprint' => $consent,
                'membership_fingerprint' => $membership,
                'credential_fingerprint' => $credential,
                'issued_at_utc' => now(),
                'expires_at_utc' => now()->addSeconds($ttl),
            ]);
        }, 3);
    }

    public function refresh(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedRelayLease $lease): AiCommonSharedRelayLease
    {
        if (! config('ai-common-realtime.enabled')) {
            throw ValidationException::withMessages(['lease' => SafeRealtimeReason::AudioSendDisabled->value]);
        }

        return DB::transaction(function () use ($actor, $organization, $conversation, $session, $lease): AiCommonSharedRelayLease {
            $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $roster = $this->access->assertConsents($actor, $organization, $conversation, $lockedSession, [
                AiCommonSharedSessionConsent::PURPOSE_RECORDING,
                AiCommonSharedSessionConsent::PURPOSE_EXTERNAL_ASR,
                AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
            ]);
            $stream = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($lease->ai_common_shared_capture_stream_id);
            $locked = AiCommonSharedRelayLease::query()->lockForUpdate()->findOrFail($lease->id);
            [$audience, $consent, $membership, $credential] = $this->fingerprints($roster);
            if ($lockedSession->state !== AiCommonSharedSession::STATE_ACTIVE
                || $stream->ai_common_shared_session_id !== $lockedSession->id
                || $stream->state !== AiCommonSharedCaptureStream::STATE_RECORDING
                || $stream->generation !== $locked->generation
                || $locked->state !== AiCommonSharedRelayLease::STATE_ACTIVE
                || $locked->expires_at_utc->lte(now())
                || $locked->ai_common_shared_session_id !== $lockedSession->id
                || ! hash_equals($locked->audience_fingerprint, $audience)
                || ! hash_equals($locked->consent_fingerprint, $consent)
                || ! hash_equals($locked->membership_fingerprint, $membership)
                || ! hash_equals($locked->credential_fingerprint, $credential)) {
                throw ValidationException::withMessages(['lease' => SafeRealtimeReason::AuthorizationChanged->value]);
            }
            $locked->update(['lease_version' => $locked->lease_version + 1, 'refreshed_at_utc' => now(), 'expires_at_utc' => now()->addSeconds($this->ttl())]);

            return $locked->fresh();
        }, 3);
    }

    public function isManaged(AiCommonSharedCaptureStream $stream): bool
    {
        return config('ai-common-realtime.enabled')
            && AiCommonSharedRelayLease::query()->where('ai_common_shared_capture_stream_id', $stream->id)
                ->where('generation', $stream->generation)->exists();
    }

    public function enqueueControl(AiCommonSharedCaptureStream $stream, string $eventType, SafeRealtimeReason $reason, ?int $cutoffSample = null): ?AiCommonSharedRelayControlEvent
    {
        if (! config('ai-common-realtime.enabled')) {
            return null;
        }

        return DB::transaction(function () use ($stream, $eventType, $reason, $cutoffSample): ?AiCommonSharedRelayControlEvent {
            $lease = AiCommonSharedRelayLease::query()->where('ai_common_shared_capture_stream_id', $stream->id)
                ->where('generation', $stream->generation)->lockForUpdate()->first();
            if (! $lease) {
                return null;
            }
            if ($lease->state === AiCommonSharedRelayLease::STATE_ACTIVE) {
                $lease->update(['state' => AiCommonSharedRelayLease::STATE_REVOKING, 'lease_version' => $lease->lease_version + 1, 'revoked_at_utc' => now(), 'safe_reason_code' => $reason->value]);
            }

            return AiCommonSharedRelayControlEvent::query()->create([
                'operation_id' => (string) Str::uuid(), 'relay_lease_id' => $lease->id,
                'event_type' => $eventType, 'target_generation' => $stream->generation,
                'cutoff_sample' => $cutoffSample, 'safe_reason_code' => $reason->value,
                'occurred_at_utc' => now(),
            ]);
        }, 3);
    }

    private function fingerprints($roster): array
    {
        $rows = $roster->sortBy('id')->values();
        $audience = hash('sha256', $rows->map(fn ($row) => implode(':', [$row->id, $row->participant_audience_epoch]))->implode('|'));
        $membership = hash('sha256', $rows->map(fn ($row) => implode(':', [$row->user_id, $row->membership_access_epoch]))->implode('|'));
        $credential = hash('sha256', $rows->map(fn ($row) => implode(':', [$row->user_id, $row->credential_generation]))->implode('|'));
        $consents = AiCommonSharedSessionConsent::query()->whereIn('ai_common_shared_session_participant_id', $rows->pluck('id'))
            ->whereIn('purpose', [AiCommonSharedSessionConsent::PURPOSE_RECORDING, AiCommonSharedSessionConsent::PURPOSE_EXTERNAL_ASR, AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING])
            ->orderBy('ai_common_shared_session_participant_id')->orderBy('purpose')->orderByDesc('revision_no')->get()
            ->unique(fn ($row) => $row->ai_common_shared_session_participant_id.'|'.$row->purpose)
            ->map(fn ($row) => implode(':', [$row->ai_common_shared_session_participant_id, $row->purpose, $row->revision_no, $row->status]))->sort()->values();

        return [$audience, hash('sha256', $consents->implode('|')), $membership, $credential];
    }

    private function ttl(): int
    {
        $ttl = (int) config('ai-common-realtime.lease_ttl_seconds');
        $refresh = (int) config('ai-common-realtime.lease_refresh_seconds');
        if ($ttl < 6 || $ttl > 30 || $refresh < 2 || $refresh >= $ttl) {
            throw ValidationException::withMessages(['lease' => 'Unsafe lease TTL/refresh configuration.']);
        }

        return $ttl;
    }
}
