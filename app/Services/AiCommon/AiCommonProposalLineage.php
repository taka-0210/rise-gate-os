<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\AiProposal;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AiCommonProposalLineage
{
    public function __construct(private readonly AiCommonSourceManifest $manifest) {}

    public function sourceMessage(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        ?string $publicId,
    ): ?AiCommonMessage {
        if (blank($publicId)) {
            return null;
        }
        $message = $conversation->messages()->with('sourceRevisions.conversation')
            ->where('public_id', $publicId)
            ->where('role', AiCommonMessage::ROLE_ASSISTANT)
            ->firstOrFail();
        if ($message->source_lineage_version !== AiCommonMessage::SOURCE_LINEAGE_V1) {
            throw ValidationException::withMessages(['proposal' => 'Legacy AI responses cannot be used to create a source-derived proposal.']);
        }
        foreach ($message->sourceRevisions as $revision) {
            $this->manifest->authorizeRevision($actor, $organization, $revision);
        }

        return $message;
    }

    public function authorizeProposal(User $actor, AiProposal $proposal): void
    {
        $proposal->loadMissing([
            'commonConversation.organization',
            'commonHandoff.sourceMessage.sourceRevisions.conversation',
            'sourceRevisions.conversation',
        ]);
        $handoff = $proposal->commonHandoff;
        if (! $handoff || $handoff->source_lineage_version === null) {
            return;
        }
        if ($handoff->source_lineage_version !== AiCommonMessage::SOURCE_LINEAGE_V1 || ! $handoff->sourceMessage) {
            throw ValidationException::withMessages(['proposal' => 'Proposal source lineage is unavailable.']);
        }
        if ($handoff->sourceMessage->ai_common_conversation_id !== $proposal->ai_common_conversation_id) {
            throw ValidationException::withMessages(['proposal' => 'Proposal source lineage does not belong to this conversation.']);
        }
        $messageRevisionIds = $handoff->sourceMessage->sourceRevisions->pluck('id')->sort()->values()->all();
        $proposalRevisionIds = $proposal->sourceRevisions->pluck('id')->sort()->values()->all();
        if ($messageRevisionIds !== $proposalRevisionIds) {
            throw ValidationException::withMessages(['proposal' => 'Proposal source lineage is incomplete.']);
        }
        foreach ($proposal->sourceRevisions as $revision) {
            $this->manifest->authorizeRevision($actor, $proposal->commonConversation->organization, $revision);
        }
    }
}
