<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedAudioWindow;
use App\Models\AiCommonSharedCaptureStream;
use App\Models\AiCommonSharedCoState;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\AiCommonSharedSessionParticipant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonSharedSessionWriter
{
    private const MAX_SESSION_MINUTES = 180;

    public function __construct(
        private readonly AiCommonSharedAccess $shared,
        private readonly AiCommonSharedSessionAccess $access,
        private readonly AiCommonSharedAudioCleanup $cleanup,
    ) {}

    public function prepare(User $actor, Organization $organization, AiCommonConversation $conversation, array $input): AiCommonSharedSession
    {
        $operationId = $this->operationId($input);
        $mode = (string) ($input['mode'] ?? AiCommonSharedSession::MODE_SHARED_ROOM);
        if ($mode !== AiCommonSharedSession::MODE_SHARED_ROOM) {
            throw ValidationException::withMessages(['mode' => 'Ver.1 supports shared_room only. Distributed multi-mic capture is not available.']);
        }
        $fingerprint = hash('sha256', json_encode(['mode' => $mode], JSON_THROW_ON_ERROR));
        $audience = $this->shared->audienceSnapshot($actor, $organization, $conversation);
        if ($existing = AiCommonSharedSession::query()->where('ai_common_shared_conversation_id', $audience['shared']->id)->where('operation_id', $operationId)->first()) {
            $this->assertReplay($existing, $fingerprint);

            return $existing;
        }

        return DB::transaction(function () use ($actor, $organization, $conversation, $operationId, $mode, $fingerprint): AiCommonSharedSession {
            $lockedConversation = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $audience = $this->shared->audienceSnapshot($actor, $organization, $lockedConversation);
            if ($existing = AiCommonSharedSession::query()->where('ai_common_shared_conversation_id', $audience['shared']->id)->where('operation_id', $operationId)->first()) {
                $this->assertReplay($existing, $fingerprint);

                return $existing;
            }
            $blocking = AiCommonSharedSession::query()
                ->where('ai_common_shared_conversation_id', $audience['shared']->id)
                ->whereIn('state', [
                    AiCommonSharedSession::STATE_PREPARED,
                    AiCommonSharedSession::STATE_ACTIVE,
                    AiCommonSharedSession::STATE_PAUSED,
                    AiCommonSharedSession::STATE_INTERRUPTED,
                    AiCommonSharedSession::STATE_ENDING,
                ])->lockForUpdate()->first();
            if ($blocking) {
                throw ValidationException::withMessages(['session' => 'Only one bounded Session may be open in a Shared Conversation.']);
            }
            $host = $audience['participants']->firstWhere('user_id', $actor->id);
            $session = AiCommonSharedSession::query()->create([
                'organization_id' => $organization->id,
                'ai_common_shared_conversation_id' => $audience['shared']->id,
                'host_participant_id' => $host->id,
                'purpose_revision_id' => $audience['shared']->current_purpose_revision_id,
                'mode' => $mode,
                'state' => AiCommonSharedSession::STATE_PREPARED,
                'participant_version' => $audience['participant_version'],
                'operation_id' => $operationId,
                'payload_fingerprint' => $fingerprint,
                'hard_stop_at_utc' => now()->addMinutes(self::MAX_SESSION_MINUTES),
            ]);
            foreach ($audience['participants'] as $participant) {
                AiCommonSharedSessionParticipant::query()->create([
                    'ai_common_shared_session_id' => $session->id,
                    'ai_common_shared_participant_id' => $participant->id,
                    'user_id' => $participant->user_id,
                    'role' => $participant->id === $host->id ? 'host' : 'participant',
                    'status' => AiCommonSharedSessionParticipant::STATUS_ACTIVE,
                    'participant_audience_epoch' => $participant->audience_epoch,
                    'membership_access_epoch' => $participant->accepted_membership_epoch,
                    'credential_generation' => $participant->accepted_credential_generation,
                    'joined_at_utc' => now(),
                ]);
            }
            $this->syncState($audience['shared']->id, $session);

            return $session->fresh();
        }, 3);
    }

    public function decideConsent(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, array $input): AiCommonSharedSession
    {
        $operationId = $this->operationId($input);
        $decisions = $input['consents'] ?? [];
        if (array_diff(AiCommonSharedSessionConsent::PURPOSES, array_keys($decisions)) !== []) {
            throw ValidationException::withMessages(['consents' => 'All four consent purposes require an explicit decision.']);
        }
        foreach ($decisions as $purpose => $status) {
            if (! in_array($purpose, AiCommonSharedSessionConsent::PURPOSES, true)
                || ! in_array($status, [AiCommonSharedSessionConsent::STATUS_GRANTED, AiCommonSharedSessionConsent::STATUS_DECLINED, AiCommonSharedSessionConsent::STATUS_REVOKED], true)) {
                throw ValidationException::withMessages(['consents' => 'Invalid consent decision.']);
            }
        }
        $sessionParticipant = $this->access->authorize($actor, $organization, $conversation, $session, true);
        DB::transaction(function () use ($actor, $organization, $conversation, $session, $sessionParticipant, $operationId, $decisions): void {
            $locked = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->access->authorize($actor, $organization, $conversation->fresh(), $locked, true);
            foreach (AiCommonSharedSessionConsent::PURPOSES as $purpose) {
                $fingerprint = hash('sha256', json_encode([$locked->id, $purpose, $decisions[$purpose]], JSON_THROW_ON_ERROR));
                $existing = AiCommonSharedSessionConsent::query()
                    ->where('ai_common_shared_session_participant_id', $sessionParticipant->id)
                    ->where('operation_id', $operationId)
                    ->where('purpose', $purpose)->first();
                if ($existing) {
                    if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                        throw ValidationException::withMessages(['operation_id' => 'Consent operation was reused with different decisions.']);
                    }

                    continue;
                }
                $revision = (int) AiCommonSharedSessionConsent::query()
                    ->where('ai_common_shared_session_participant_id', $sessionParticipant->id)
                    ->where('purpose', $purpose)->max('revision_no') + 1;
                AiCommonSharedSessionConsent::query()->create([
                    'ai_common_shared_session_participant_id' => $sessionParticipant->id,
                    'decided_by_user_id' => $actor->id,
                    'purpose' => $purpose,
                    'status' => $decisions[$purpose],
                    'revision_no' => $revision,
                    'operation_id' => $operationId,
                    'payload_fingerprint' => $fingerprint,
                    'evidence_version' => 's11cd-b-p3.v1',
                    'decided_at_utc' => now(),
                    'revoked_at_utc' => $decisions[$purpose] === AiCommonSharedSessionConsent::STATUS_REVOKED ? now() : null,
                ]);
            }
            if (collect($decisions)->contains(fn ($status) => $status !== AiCommonSharedSessionConsent::STATUS_GRANTED)
                && in_array($locked->state, [AiCommonSharedSession::STATE_ACTIVE, AiCommonSharedSession::STATE_PAUSED], true)) {
                $this->interruptLocked($locked, 'consent_changed');
            }
        }, 3);

        return $session->fresh();
    }

    public function activate(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session): AiCommonSharedSession
    {
        return DB::transaction(function () use ($actor, $organization, $conversation, $session): AiCommonSharedSession {
            $locked = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->access->authorize($actor, $organization, $conversation, $locked, true);
            if (! in_array($locked->state, [AiCommonSharedSession::STATE_PREPARED, AiCommonSharedSession::STATE_INTERRUPTED], true)) {
                throw ValidationException::withMessages(['session' => 'Only a prepared or safely interrupted Session can start.']);
            }
            $this->access->assertConsents($actor, $organization, $conversation, $locked, [
                AiCommonSharedSessionConsent::PURPOSE_RECORDING,
                AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
            ]);
            if ($locked->hard_stop_at_utc->lte(now())) {
                throw ValidationException::withMessages(['session' => 'The bounded Session limit was reached. Start a new Session.']);
            }
            $locked->update([
                'state' => AiCommonSharedSession::STATE_ACTIVE,
                'sequence' => $locked->sequence + 1,
                'version' => $locked->version + 1,
                'started_at_utc' => $locked->started_at_utc ?? now(),
                'paused_at_utc' => null,
                'interrupted_at_utc' => null,
                'safe_error_code' => null,
            ]);
            $this->syncState($locked->ai_common_shared_conversation_id, $locked->fresh());

            return $locked->fresh();
        }, 3);
    }

    public function pause(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session): AiCommonSharedSession
    {
        return $this->transition($actor, $organization, $conversation, $session, AiCommonSharedSession::STATE_ACTIVE, AiCommonSharedSession::STATE_PAUSED);
    }

    public function resume(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session): AiCommonSharedSession
    {
        $this->access->assertConsents($actor, $organization, $conversation, $session, [
            AiCommonSharedSessionConsent::PURPOSE_RECORDING,
            AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
        ]);

        return $this->transition($actor, $organization, $conversation, $session, AiCommonSharedSession::STATE_PAUSED, AiCommonSharedSession::STATE_ACTIVE);
    }

    public function end(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session): AiCommonSharedSession
    {
        return DB::transaction(function () use ($actor, $organization, $conversation, $session): AiCommonSharedSession {
            $locked = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->access->authorize($actor, $organization, $conversation, $locked);
            if ($locked->state === AiCommonSharedSession::STATE_ENDED) {
                return $locked;
            }
            $locked->update([
                'state' => AiCommonSharedSession::STATE_ENDING,
                'sequence' => $locked->sequence + 1,
                'version' => $locked->version + 1,
                'ending_at_utc' => now(),
                'end_cutoff_sequence' => $locked->sequence,
            ]);
            $this->fenceStreams($locked, AiCommonSharedCaptureStream::STATE_STOPPED, 'session_ended');
            $locked->update([
                'state' => AiCommonSharedSession::STATE_ENDED,
                'sequence' => $locked->sequence + 1,
                'version' => $locked->version + 1,
                'ended_at_utc' => now(),
            ]);
            $this->syncState($locked->ai_common_shared_conversation_id, $locked->fresh());

            return $locked->fresh();
        }, 3);
    }

    public function join(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session): AiCommonSharedSessionParticipant
    {
        return DB::transaction(function () use ($actor, $organization, $conversation, $session): AiCommonSharedSessionParticipant {
            $locked = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $participant = $this->shared->authorizeParticipant($actor, $organization, $conversation, true);
            $existing = AiCommonSharedSessionParticipant::query()
                ->where('ai_common_shared_session_id', $locked->id)
                ->where('ai_common_shared_participant_id', $participant->id)
                ->first();
            if ($existing?->status === AiCommonSharedSessionParticipant::STATUS_ACTIVE) {
                return $existing;
            }
            $row = $existing ?? new AiCommonSharedSessionParticipant([
                'ai_common_shared_session_id' => $locked->id,
                'ai_common_shared_participant_id' => $participant->id,
            ]);
            $row->fill([
                'user_id' => $actor->id,
                'role' => 'participant',
                'status' => AiCommonSharedSessionParticipant::STATUS_ACTIVE,
                'participant_audience_epoch' => $participant->audience_epoch,
                'membership_access_epoch' => $participant->accepted_membership_epoch,
                'credential_generation' => $participant->accepted_credential_generation,
                'joined_at_utc' => now(),
                'left_at_utc' => null,
            ])->save();
            $locked->update(['participant_version' => $participant->sharedConversation->participant_version, 'sequence' => $locked->sequence + 1, 'version' => $locked->version + 1]);
            if (in_array($locked->state, [AiCommonSharedSession::STATE_ACTIVE, AiCommonSharedSession::STATE_PAUSED], true)) {
                $this->interruptLocked($locked, 'participant_joined');
            }

            return $row->fresh();
        }, 3);
    }

    public function startStream(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, array $input): AiCommonSharedCaptureStream
    {
        $operationId = $this->operationId($input);
        $clientId = (string) ($input['client_instance_id'] ?? '');
        $mode = (string) ($input['mode'] ?? AiCommonSharedSession::MODE_SHARED_ROOM);
        if (! Str::isUuid($clientId) || $mode !== AiCommonSharedSession::MODE_SHARED_ROOM) {
            throw ValidationException::withMessages(['stream' => 'Only a valid shared_room capture stream is supported.']);
        }
        $fingerprint = hash('sha256', json_encode([$clientId, $mode], JSON_THROW_ON_ERROR));
        $sessionParticipant = $this->access->authorize($actor, $organization, $conversation, $session, true);
        $this->access->assertConsents($actor, $organization, $conversation, $session, [
            AiCommonSharedSessionConsent::PURPOSE_RECORDING,
            AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
        ]);

        return DB::transaction(function () use ($actor, $organization, $conversation, $session, $operationId, $clientId, $mode, $fingerprint, $sessionParticipant): AiCommonSharedCaptureStream {
            $locked = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->access->authorize($actor, $organization, $conversation, $locked, true);
            if ($locked->state !== AiCommonSharedSession::STATE_ACTIVE) {
                throw ValidationException::withMessages(['session' => 'Capture requires an active Session.']);
            }
            if ($existing = AiCommonSharedCaptureStream::query()->where('ai_common_shared_session_id', $locked->id)->where('operation_id', $operationId)->first()) {
                if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                    throw ValidationException::withMessages(['operation_id' => 'Stream operation was reused with different content.']);
                }

                return $existing;
            }
            if (AiCommonSharedCaptureStream::query()->where('ai_common_shared_session_id', $locked->id)->whereIn('state', AiCommonSharedCaptureStream::ACTIVE_STATES)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['stream' => 'Ver.1 allows one active capture stream only.']);
            }
            $generation = (int) AiCommonSharedCaptureStream::query()->where('ai_common_shared_session_id', $locked->id)->max('generation') + 1;
            $stream = AiCommonSharedCaptureStream::query()->create([
                'ai_common_shared_session_id' => $locked->id,
                'operator_session_participant_id' => $sessionParticipant->id,
                'client_instance_id' => $clientId,
                'mode' => $mode,
                'state' => AiCommonSharedCaptureStream::STATE_RECORDING,
                'generation' => $generation,
                'operation_id' => $operationId,
                'payload_fingerprint' => $fingerprint,
                'started_at_utc' => now(),
            ]);
            $locked->update(['sequence' => $locked->sequence + 1, 'version' => $locked->version + 1]);
            $this->syncState($locked->ai_common_shared_conversation_id, $locked->fresh());

            return $stream;
        }, 3);
    }

    public function stopStream(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedCaptureStream $stream, bool $cancel = false): AiCommonSharedCaptureStream
    {
        return DB::transaction(function () use ($actor, $organization, $conversation, $session, $stream, $cancel): AiCommonSharedCaptureStream {
            $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $sessionParticipant = $this->access->authorize($actor, $organization, $conversation, $lockedSession);
            $locked = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($stream->id);
            if ($locked->ai_common_shared_session_id !== $lockedSession->id
                || $locked->operator_session_participant_id !== $sessionParticipant->id) {
                throw ValidationException::withMessages(['stream' => 'The capture stream does not belong to this operator or Session.']);
            }
            if (! in_array($locked->state, AiCommonSharedCaptureStream::ACTIVE_STATES, true)) {
                return $locked;
            }
            $state = $cancel ? AiCommonSharedCaptureStream::STATE_CANCELLED : AiCommonSharedCaptureStream::STATE_STOPPED;
            $locked->update([
                'state' => $state,
                'version' => $locked->version + 1,
                'sequence' => $locked->sequence + 1,
                $cancel ? 'cancelled_at_utc' : 'stopped_at_utc' => now(),
            ]);
            $lockedSession->update(['sequence' => $lockedSession->sequence + 1, 'version' => $lockedSession->version + 1]);
            if ($cancel) {
                foreach ($locked->audioWindows()->whereNotIn('state', [AiCommonSharedAudioWindow::STATE_TRANSCRIBED, AiCommonSharedAudioWindow::STATE_DISCARDED])->get() as $window) {
                    $window->update(['state' => AiCommonSharedAudioWindow::STATE_CANCELLED, 'version' => $window->version + 1]);
                    $this->cleanup->cleanup($window->fresh());
                }
            }
            $this->syncState($lockedSession->ai_common_shared_conversation_id, $lockedSession->fresh());

            return $locked->fresh();
        }, 3);
    }

    public function interruptForConversation(AiCommonConversation $conversation, string $safeCode): void
    {
        $sharedId = $conversation->sharedConversation()->value('id');
        if (! $sharedId) {
            return;
        }
        DB::transaction(function () use ($sharedId, $safeCode): void {
            $session = AiCommonSharedSession::query()
                ->where('ai_common_shared_conversation_id', $sharedId)
                ->whereIn('state', [AiCommonSharedSession::STATE_ACTIVE, AiCommonSharedSession::STATE_PAUSED])
                ->lockForUpdate()->first();
            if ($session) {
                $this->interruptLocked($session, $safeCode);
            }
        }, 3);
    }

    private function transition(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, string $from, string $to): AiCommonSharedSession
    {
        return DB::transaction(function () use ($actor, $organization, $conversation, $session, $from, $to): AiCommonSharedSession {
            $locked = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->access->authorize($actor, $organization, $conversation, $locked, true);
            if ($locked->state !== $from) {
                throw ValidationException::withMessages(['session' => "Session cannot transition from {$locked->state} to {$to}."]);
            }
            $locked->update([
                'state' => $to,
                'sequence' => $locked->sequence + 1,
                'version' => $locked->version + 1,
                'paused_at_utc' => $to === AiCommonSharedSession::STATE_PAUSED ? now() : null,
            ]);
            AiCommonSharedCaptureStream::query()
                ->where('ai_common_shared_session_id', $locked->id)
                ->whereIn('state', AiCommonSharedCaptureStream::ACTIVE_STATES)
                ->update(['state' => $to === AiCommonSharedSession::STATE_PAUSED ? AiCommonSharedCaptureStream::STATE_PAUSED : AiCommonSharedCaptureStream::STATE_RECORDING]);
            $this->syncState($locked->ai_common_shared_conversation_id, $locked->fresh());

            return $locked->fresh();
        }, 3);
    }

    private function interruptLocked(AiCommonSharedSession $session, string $safeCode): void
    {
        $session->update([
            'state' => AiCommonSharedSession::STATE_INTERRUPTED,
            'sequence' => $session->sequence + 1,
            'version' => $session->version + 1,
            'interrupted_at_utc' => now(),
            'safe_error_code' => $safeCode,
        ]);
        $this->fenceStreams($session, AiCommonSharedCaptureStream::STATE_INTERRUPTED, $safeCode);
        $this->syncState($session->ai_common_shared_conversation_id, $session->fresh());
    }

    private function fenceStreams(AiCommonSharedSession $session, string $state, string $safeCode): void
    {
        AiCommonSharedCaptureStream::query()
            ->where('ai_common_shared_session_id', $session->id)
            ->whereIn('state', AiCommonSharedCaptureStream::ACTIVE_STATES)
            ->update([
                'state' => $state,
                'version' => DB::raw('version + 1'),
                'sequence' => DB::raw('sequence + 1'),
                $state === AiCommonSharedCaptureStream::STATE_STOPPED ? 'stopped_at_utc' : 'interrupted_at_utc' => now(),
                'safe_error_code' => $safeCode,
            ]);
    }

    private function syncState(int $sharedConversationId, AiCommonSharedSession $session): void
    {
        $state = AiCommonSharedCoState::query()->firstOrCreate(
            ['ai_common_shared_conversation_id' => $sharedConversationId],
            ['state' => 'idle', 'phase' => 'idle', 'sequence' => 0, 'version' => 1],
        );
        $state->update([
            'current_session_id' => $session->id,
            'session_state' => $session->state,
            'session_sequence' => $session->sequence,
            'version' => $state->version + 1,
        ]);
    }

    private function operationId(array $input): string
    {
        $operationId = (string) ($input['operation_id'] ?? '');
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid operation ID is required.']);
        }

        return $operationId;
    }

    private function assertReplay(AiCommonSharedSession $session, string $fingerprint): void
    {
        if (! hash_equals($session->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => 'Session operation was reused with different content.']);
        }
    }
}
