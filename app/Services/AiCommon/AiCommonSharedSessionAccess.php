<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\AiCommonSharedSessionParticipant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AiCommonSharedSessionAccess
{
    public function __construct(private readonly AiCommonSharedAccess $shared) {}

    public function authorize(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        bool $requireMutable = false,
    ): AiCommonSharedSessionParticipant {
        $participant = $this->shared->authorizeParticipant($actor, $organization, $conversation, $requireMutable);
        $shared = $participant->sharedConversation;
        if ($session->organization_id !== $organization->id
            || $session->ai_common_shared_conversation_id !== $shared->id) {
            throw new AuthorizationException;
        }
        $sessionParticipant = AiCommonSharedSessionParticipant::query()
            ->where('ai_common_shared_session_id', $session->id)
            ->where('ai_common_shared_participant_id', $participant->id)
            ->where('status', AiCommonSharedSessionParticipant::STATUS_ACTIVE)
            ->first();
        if (! $sessionParticipant
            || $sessionParticipant->user_id !== $actor->id
            || $sessionParticipant->participant_audience_epoch !== $participant->audience_epoch
            || $sessionParticipant->membership_access_epoch !== $participant->accepted_membership_epoch
            || $sessionParticipant->credential_generation !== $participant->accepted_credential_generation) {
            throw new AuthorizationException;
        }

        return $sessionParticipant;
    }

    public function assertCurrentAudience(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
    ): Collection {
        $current = $this->shared->activeAudience($actor, $organization, $conversation);
        $roster = AiCommonSharedSessionParticipant::query()
            ->where('ai_common_shared_session_id', $session->id)
            ->where('status', AiCommonSharedSessionParticipant::STATUS_ACTIVE)
            ->with(['participant', 'user'])
            ->orderBy('ai_common_shared_participant_id')
            ->get();
        if ($current->pluck('id')->sort()->values()->all() !== $roster->pluck('ai_common_shared_participant_id')->sort()->values()->all()) {
            throw ValidationException::withMessages(['session' => 'Session roster changed. Join or interrupt the Session before continuing.']);
        }
        foreach ($roster as $row) {
            $participant = $row->participant;
            if ($participant->status !== 'active'
                || $row->participant_audience_epoch !== $participant->audience_epoch
                || $row->membership_access_epoch !== $participant->accepted_membership_epoch
                || $row->credential_generation !== $participant->accepted_credential_generation
                || ! $row->user->is_active) {
                throw new AuthorizationException;
            }
        }

        return $roster;
    }

    public function assertConsents(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        array $purposes,
    ): Collection {
        $roster = $this->assertCurrentAudience($actor, $organization, $conversation, $session);
        foreach ($roster as $participant) {
            foreach ($purposes as $purpose) {
                if (! in_array($purpose, AiCommonSharedSessionConsent::PURPOSES, true)) {
                    throw ValidationException::withMessages(['consent' => 'Unknown consent purpose.']);
                }
                $latest = AiCommonSharedSessionConsent::query()
                    ->where('ai_common_shared_session_participant_id', $participant->id)
                    ->where('purpose', $purpose)
                    ->orderByDesc('revision_no')
                    ->first();
                if (! $latest || $latest->status !== AiCommonSharedSessionConsent::STATUS_GRANTED) {
                    throw ValidationException::withMessages(['consent' => 'Every active Participant must grant the required Session consent.']);
                }
            }
        }

        return $roster;
    }
}
