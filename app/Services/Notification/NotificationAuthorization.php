<?php

namespace App\Services\Notification;

use App\Models\AiCommonMessage;
use App\Models\AiCommonSharedParticipant;
use App\Models\AiProposal;
use App\Models\Capture;
use App\Models\CompanyNotification;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\Task;
use App\Services\AiCommon\AiCommonSharedAccess;
use App\Services\AiCommon\AiCommonSharedProposalGuard;
use App\Services\Capture\CaptureAccess;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Throwable;

class NotificationAuthorization
{
    public function __construct(
        private readonly ProjectExecutionAccess $projects,
        private readonly CaptureAccess $captures,
        private readonly AiCommonSharedAccess $shared,
        private readonly AiCommonSharedProposalGuard $sharedProposals,
    ) {}

    public function allowed(CompanyNotification $notification): bool
    {
        $user = $notification->recipient;
        if (! $user?->is_active || $user->credential_generation !== $notification->credential_generation) {
            return false;
        }
        $membership = OrganizationUser::query()->where('organization_id', $notification->organization_id)
            ->where('user_id', $user->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE)->first();
        if (! $membership || $membership->access_epoch !== $notification->membership_access_epoch) {
            return false;
        }
        $eligibility = $user->productAccountEligibility;
        if (! $eligibility || ($eligibility->mode === ProductAccountEligibility::MODE_SINGLE
            && $eligibility->product_organization_id !== $notification->organization_id)) {
            return false;
        }
        if ($notification->source_type === 'Capture') {
            $capture = Capture::query()->find($notification->source_id);

            return $capture && $capture->organization_id === $notification->organization_id && $capture->recipient_user_id === $user->id && $capture->status === Capture::STATUS_OPEN && $this->captures->canRead($user, $capture);
        }
        if ($notification->source_type === 'SharedInvitation') {
            $participant = AiCommonSharedParticipant::query()->with('sharedConversation.conversation')->find($notification->source_id);

            return $participant
                && $participant->user_id === $user->id
                && $participant->status === AiCommonSharedParticipant::STATUS_INVITED
                && $participant->sharedConversation->organization_id === $notification->organization_id
                && $participant->sharedConversation->conversation->status === 'active';
        }
        if ($notification->source_type === 'SharedMessage') {
            $message = AiCommonMessage::query()->with('conversation')->find($notification->source_id);
            if (! $message || $message->conversation->organization_id !== $notification->organization_id) {
                return false;
            }
            try {
                $this->shared->authorizeParticipant($user, $notification->organization, $message->conversation, true);

                return true;
            } catch (Throwable) {
                return false;
            }
        }
        if ($notification->source_type === 'SharedProposal') {
            $proposal = AiProposal::query()->with(['commonConversation.organization', 'items'])->find($notification->source_id);
            if (! $proposal || $proposal->organization_id !== $notification->organization_id || $proposal->status !== AiProposal::STATUS_PENDING) {
                return false;
            }
            try {
                $this->sharedProposals->authorize($user, $proposal, true);

                return true;
            } catch (Throwable) {
                return false;
            }
        }
        if ($notification->source_type !== 'Task') {
            return true;
        }
        $task = Task::query()->with('project')->find($notification->source_id);
        if (! $task || $task->organization_id !== $notification->organization_id || ! $this->projects->canRead($user, $task->project)) {
            return false;
        }

        return match ($notification->type) {
            CompanyNotification::TYPE_ACTION_ASSIGNED, CompanyNotification::TYPE_ACTION_RETURNED => $task->assigned_to === $user->id,
            CompanyNotification::TYPE_REVIEW_ATTENTION => $task->reviewer_user_id === $user->id && $task->status === Task::STATUS_REVIEW_PENDING,
            default => false,
        };
    }
}
