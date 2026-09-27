<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedParticipant;
use App\Models\AiCommonSharedProposalContext;
use App\Models\AiProposal;
use App\Models\BusinessDomain;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\Capture\CaptureAccess;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class AiCommonSharedProposalGuard
{
    public function __construct(
        private readonly AiCommonSharedAccess $shared,
        private readonly ProjectExecutionAccess $projects,
        private readonly BusinessDomainAccess $domains,
        private readonly CaptureAccess $captures,
    ) {}

    public function creationContext(User $actor, Organization $organization, AiCommonConversation $conversation, string $operation, ?string $targetPublicId, int $approvalRecipientUserId): array
    {
        $audience = $this->shared->audienceSnapshot($actor, $organization, $conversation);
        $recipient = $audience['participants']->firstWhere('user_id', $approvalRecipientUserId);
        if (! $recipient instanceof AiCommonSharedParticipant) {
            throw new AuthorizationException;
        }
        $target = $this->target($organization, $operation, $targetPublicId);
        foreach ($audience['participants'] as $participant) {
            $this->authorizeRead($participant->user, $organization, $operation, $target);
        }
        $this->authorizeWrite($actor, $organization, $operation, $target);
        $this->authorizeWrite($recipient->user, $organization, $operation, $target);
        $targetFingerprint = hash('sha256', json_encode($target, JSON_THROW_ON_ERROR));

        return $audience + ['recipient' => $recipient, 'target_fingerprint' => $targetFingerprint];
    }

    public function record(AiProposal $proposal, array $context): void
    {
        AiCommonSharedProposalContext::query()->create([
            'ai_proposal_id' => $proposal->id,
            'ai_common_shared_conversation_id' => $context['shared']->id,
            'created_by_participant_id' => $context['participants']->firstWhere('user_id', $proposal->requested_by)->id,
            'approval_recipient_user_id' => $context['recipient']->user_id,
            'participant_version' => $context['participant_version'],
            'audience_fingerprint' => $context['fingerprint'],
            'target_snapshot_fingerprint' => $context['target_fingerprint'],
            'audience_snapshot' => $context['snapshot'],
        ]);
    }

    public function authorize(User $actor, AiProposal $proposal, bool $approval = false): AiCommonSharedProposalContext
    {
        $proposal->loadMissing(['commonConversation.organization', 'items']);
        $context = AiCommonSharedProposalContext::query()->where('ai_proposal_id', $proposal->id)->first();
        if (! $context) {
            throw ValidationException::withMessages(['proposal' => 'Shared Proposal authorization context is unavailable.']);
        }
        if ($approval && $context->approval_recipient_user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $item = $proposal->items->sole();
        $current = $this->creationContext(
            $actor,
            $proposal->commonConversation->organization,
            $proposal->commonConversation,
            $item->entity_type,
            $proposal->target_public_id,
            $context->approval_recipient_user_id,
        );
        if ((int) $context->participant_version !== $current['participant_version']
            || ! hash_equals($context->audience_fingerprint, $current['fingerprint'])
            || ($proposal->status !== AiProposal::STATUS_APPLIED
                && ! hash_equals($context->target_snapshot_fingerprint, $current['target_fingerprint']))) {
            throw ValidationException::withMessages(['proposal' => 'Shared audience or target changed; create a new Proposal.']);
        }

        return $context;
    }

    private function target(Organization $organization, string $operation, ?string $publicId): array
    {
        return match ($operation) {
            AiCommonProposalContract::CAPTURE_CREATE => ['type' => 'capture', 'id' => null, 'version' => null],
            AiCommonProposalContract::ACTION_CREATE, AiCommonProposalContract::PROJECT_UPDATE => (function () use ($organization, $publicId): array {
                $project = Project::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();

                return ['type' => 'project', 'id' => $project->public_id, 'version' => (int) $project->plan_version];
            })(),
            AiCommonProposalContract::ACTION_UPDATE => (function () use ($organization, $publicId): array {
                $action = Task::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();

                return ['type' => 'action', 'id' => $action->public_id, 'version' => (int) $action->plan_version];
            })(),
            AiCommonProposalContract::DOMAIN_UPDATE => (function () use ($organization, $publicId): array {
                $domain = BusinessDomain::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->where('status', BusinessDomain::STATUS_ACTIVE)->firstOrFail();

                return ['type' => 'business_domain', 'id' => $domain->public_id, 'version' => (int) $domain->version];
            })(),
            default => throw new AuthorizationException,
        };
    }

    private function authorizeRead(User $user, Organization $organization, string $operation, array $target): void
    {
        $allowed = match ($operation) {
            AiCommonProposalContract::CAPTURE_CREATE => $this->captures->canParticipate($user, $organization),
            AiCommonProposalContract::ACTION_CREATE, AiCommonProposalContract::PROJECT_UPDATE => $this->projects->canRead($user, Project::query()->where('organization_id', $organization->id)->where('public_id', $target['id'])->firstOrFail()),
            AiCommonProposalContract::ACTION_UPDATE => $this->projects->canRead($user, Task::query()->where('organization_id', $organization->id)->where('public_id', $target['id'])->firstOrFail()->project),
            AiCommonProposalContract::DOMAIN_UPDATE => (function () use ($user, $organization): bool {
                $this->domains->authorizeView($user, $organization);

                return true;
            })(),
            default => false,
        };
        if (! $allowed) {
            throw new AuthorizationException;
        }
    }

    private function authorizeWrite(User $user, Organization $organization, string $operation, array $target): void
    {
        $allowed = match ($operation) {
            AiCommonProposalContract::CAPTURE_CREATE => $this->captures->canParticipate($user, $organization),
            AiCommonProposalContract::ACTION_CREATE => $this->projects->canCreateAction($user, Project::query()->where('organization_id', $organization->id)->where('public_id', $target['id'])->firstOrFail()),
            AiCommonProposalContract::ACTION_UPDATE => $this->projects->canEditAction($user, Task::query()->where('organization_id', $organization->id)->where('public_id', $target['id'])->firstOrFail()),
            AiCommonProposalContract::PROJECT_UPDATE => $this->projects->canManageStructure($user, Project::query()->where('organization_id', $organization->id)->where('public_id', $target['id'])->firstOrFail()),
            AiCommonProposalContract::DOMAIN_UPDATE => $this->domains->canEdit($user, $organization),
            default => false,
        };
        if (! $allowed) {
            throw new AuthorizationException;
        }
    }
}
