<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedConversation;
use App\Models\AiCommonSharedParticipant;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class AiCommonSharedAccess
{
    public function __construct(private readonly AiCommonAccess $common) {}

    public function authorizeParticipant(
        User $user,
        Organization $organization,
        AiCommonConversation $conversation,
        bool $requireActive = false,
        bool $lock = false,
    ): AiCommonSharedParticipant {
        $membership = $this->common->authorizeOrganization($user, $organization, $lock);
        if ($conversation->organization_id !== $organization->id
            || $conversation->conversation_kind !== AiCommonConversation::KIND_SHARED) {
            throw new AuthorizationException;
        }
        if ($requireActive && $conversation->status !== AiCommonConversation::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['conversation' => 'Archived Shared Conversation cannot be changed.']);
        }

        $sharedQuery = AiCommonSharedConversation::query()
            ->where('ai_common_conversation_id', $conversation->id)
            ->where('organization_id', $organization->id);
        if ($lock) {
            $sharedQuery->lockForUpdate();
        }
        $shared = $sharedQuery->first();
        if (! $shared) {
            throw new AuthorizationException;
        }

        $participantQuery = AiCommonSharedParticipant::query()
            ->where('ai_common_shared_conversation_id', $shared->id)
            ->where('user_id', $user->id)
            ->where('status', AiCommonSharedParticipant::STATUS_ACTIVE);
        if ($lock) {
            $participantQuery->lockForUpdate();
        }
        $participant = $participantQuery->first();
        if (! $participant
            || $participant->accepted_membership_epoch !== $membership->access_epoch
            || $participant->accepted_credential_generation !== $user->credential_generation) {
            throw new AuthorizationException;
        }

        $participant->setRelation('sharedConversation', $shared);

        return $participant;
    }

    public function authorizeOwner(
        User $user,
        Organization $organization,
        AiCommonConversation $conversation,
        bool $requireActive = false,
        bool $lock = false,
    ): AiCommonSharedParticipant {
        $participant = $this->authorizeParticipant(
            $user,
            $organization,
            $conversation,
            $requireActive,
            $lock,
        );
        $shared = $participant->sharedConversation;
        if ($participant->role !== AiCommonSharedParticipant::ROLE_OWNER
            || $shared->owner_user_id !== $user->id) {
            throw new AuthorizationException;
        }

        return $participant;
    }

    public function eligibleMembership(
        User $user,
        Organization $organization,
        bool $lock = false,
    ): OrganizationUser {
        return $this->common->authorizeOrganization($user, $organization, $lock);
    }

    public function authorizeInvitation(
        User $user,
        Organization $organization,
        AiCommonSharedParticipant $participant,
        bool $lock = false,
    ): OrganizationUser {
        $membership = $this->eligibleMembership($user, $organization, $lock);
        $shared = $participant->sharedConversation()->firstOrFail();
        $conversation = $shared->conversation()->firstOrFail();
        if ($participant->user_id !== $user->id
            || $participant->status !== AiCommonSharedParticipant::STATUS_INVITED
            || $shared->organization_id !== $organization->id
            || $conversation->organization_id !== $organization->id
            || $conversation->conversation_kind !== AiCommonConversation::KIND_SHARED
            || $conversation->status !== AiCommonConversation::STATUS_ACTIVE) {
            throw new AuthorizationException;
        }

        return $membership;
    }
}
