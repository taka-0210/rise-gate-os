<?php

namespace App\Http\Controllers;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedCoState;
use App\Models\AiCommonSharedParticipant;
use App\Models\AiProposal;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\AiCommon\AiCommonHumanMessageWriter;
use App\Services\AiCommon\AiCommonProposalFactory;
use App\Services\AiCommon\AiCommonProposalLineage;
use App\Services\AiCommon\AiCommonSharedAccess;
use App\Services\AiCommon\AiCommonSharedContext;
use App\Services\AiCommon\AiCommonSharedConversationReader;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedCoWriter;
use App\Services\AiProposalApplier;
use App\Services\AiProposalApprover;
use App\Services\AiProposalUndoService;
use App\Services\Notification\NotificationSourceWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiCommonSharedConversationController extends Controller
{
    public function store(Request $request, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'purpose' => ['required', 'string', 'max:4000'],
        ]);
        $conversation = $writer->create(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $validated,
        );

        return redirect()->route('ai-common.shared.show', $conversation);
    }

    public function show(Request $request, AiCommonConversation $conversation, AiCommonSharedAccess $access, AiCommonSharedConversationReader $reader, AiCommonProposalLineage $lineage): View
    {
        $organization = $request->attributes->get('currentCompany');
        $participant = $access->authorizeParticipant($request->user(), $organization, $conversation);
        $shared = $participant->sharedConversation;
        $shared->load([
            'owner', 'pendingOwner', 'currentPurposeRevision', 'purposeRevisions.createdBy',
            'participants.user',
        ]);
        $messageRows = $reader->visible($request->user(), $organization, $conversation);
        $conversation->load(['sources.currentRevision', 'proposals.items', 'proposals.applyAttempts', 'proposals.undos']);
        $conversation->setRelation('proposals', $conversation->proposals->filter(function (AiProposal $proposal) use ($request, $lineage): bool {
            try {
                $lineage->authorizeProposal($request->user(), $proposal);

                return true;
            } catch (\Throwable) {
                return false;
            }
        })->values());

        $candidateUserIds = $shared->participants->pluck('user_id');
        $inviteCandidates = User::query()
            ->where('is_active', true)
            ->whereHas('organizationMemberships', fn ($query) => $query
                ->where('organization_id', $organization->id)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE))
            ->whereHas('productAccountEligibility', fn ($query) => $query
                ->where(function ($eligibility) use ($organization): void {
                    $eligibility->where('mode', '!=', 'single')
                        ->orWhere('product_organization_id', $organization->id);
                }))
            ->whereNotIn('id', $candidateUserIds)
            ->orderBy('name')
            ->get();

        return view('ai-common.shared-show', [
            'conversation' => $conversation,
            'shared' => $shared,
            'participant' => $participant,
            'inviteCandidates' => $inviteCandidates,
            'messageRows' => $messageRows,
            'coState' => AiCommonSharedCoState::query()->where('ai_common_shared_conversation_id', $shared->id)->first(),
        ]);
    }

    public function update(Request $request, AiCommonConversation $conversation, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:160'],
            'purpose' => ['required', 'string', 'max:4000'],
        ]);
        $writer->updateIdentity($request->user(), $request->attributes->get('currentCompany'), $conversation, $validated);

        return back()->with('status', 'Name / Purpose Revisionを更新しました。');
    }

    public function invite(Request $request, AiCommonConversation $conversation, AiCommonSharedConversationWriter $writer, NotificationSourceWriter $notifications): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'invitee_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $participant = $writer->invite(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            User::query()->findOrFail($validated['invitee_user_id']),
            $validated,
        );
        $notifications->sharedInvitation($request->user(), $participant);

        return back()->with('status', 'Participantを招待しました。承諾前は本文を閲覧できません。');
    }

    public function accept(Request $request, AiCommonSharedParticipant $invitation, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $participant = $writer->accept(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $invitation,
        );

        return redirect()->route('ai-common.shared.show', $participant->sharedConversation->conversation)
            ->with('status', 'Shared Conversationへの参加を承諾しました。');
    }

    public function humanMessage(Request $request, AiCommonConversation $conversation, AiCommonHumanMessageWriter $writer, AiCommonSharedAccess $access, NotificationSourceWriter $notifications): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'content' => ['required', 'string', 'max:4000'],
            'mention_user_ids' => ['nullable', 'array', 'max:10'],
            'mention_user_ids.*' => ['integer', 'exists:users,id'],
        ]);
        $recipients = collect($validated['mention_user_ids'] ?? [])->unique()->map(function ($userId) use ($request, $conversation, $access): User {
            $recipient = User::query()->findOrFail($userId);
            $access->authorizeParticipant($recipient, $request->attributes->get('currentCompany'), $conversation, true);

            return $recipient;
        });
        $message = $writer->postShared(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $validated,
        );
        foreach ($recipients as $recipient) {
            $notifications->sharedMention($request->user(), $conversation, $message, $recipient);
        }

        return back()->with('status', 'Human Messageを保存しました。AI Requestは送信していません。');
    }

    public function source(Request $request, AiCommonConversation $conversation, AiCommonSharedContext $context): RedirectResponse
    {
        $validated = $request->validate(['resource_type' => ['required', 'string'], 'resource_public_id' => ['required', 'string', 'max:64'], 'selection_reason' => ['required', 'string', 'max:160']]);
        $context->select($request->user(), $request->attributes->get('currentCompany'), $conversation, $validated['resource_type'], $validated['resource_public_id'], $validated['selection_reason']);

        return back()->with('status', 'Shared Context selected for the complete active audience.');
    }

    public function coRequest(Request $request, AiCommonConversation $conversation, AiCommonSharedCoWriter $writer): RedirectResponse
    {
        $validated = $request->validate(['operation_id' => ['required', 'uuid'], 'content' => ['required', 'string', 'max:4000'], 'source_ids' => ['nullable', 'array', 'max:20'], 'source_ids.*' => ['integer']]);
        $writer->request($request->user(), $request->attributes->get('currentCompany'), $conversation, $validated);

        return back()->with('status', 'One Shared CO response is ready.');
    }

    public function proposal(Request $request, AiCommonConversation $conversation, AiCommonProposalFactory $factory, NotificationSourceWriter $notifications): RedirectResponse
    {
        $validated = $request->validate([
            'operation' => ['required', 'string'], 'target_public_id' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'], 'summary' => ['nullable', 'string', 'max:1000'],
            'published_summary' => ['nullable', 'string', 'max:1000'], 'source_message_public_id' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'max:100'], 'approval_recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'attributes' => ['required', 'array'],
        ]);
        $proposal = $factory->create($request->user(), $request->attributes->get('currentCompany'), $conversation, $validated);
        $notifications->sharedApprovalRequest($request->user(), $proposal, User::query()->findOrFail($validated['approval_recipient_user_id']));

        return back()->with('status', 'Shared Proposal created; no Unit write occurred.');
    }

    public function approve(Request $request, AiCommonConversation $conversation, AiProposal $proposal, AiProposalApprover $approver): RedirectResponse
    {
        if ($proposal->ai_common_conversation_id !== $conversation->id) {
            abort(404);
        }
        $approver->approve($proposal, $request->user());

        return back()->with('status', 'Shared Proposal approved.');
    }

    public function apply(Request $request, AiCommonConversation $conversation, AiProposal $proposal, AiProposalApplier $applier): RedirectResponse
    {
        if ($proposal->ai_common_conversation_id !== $conversation->id) {
            abort(404);
        }
        $applier->apply($proposal, $request->user());

        return back()->with('status', 'Shared Proposal applied through the existing Unit Writer.');
    }

    public function undo(Request $request, AiCommonConversation $conversation, AiProposal $proposal, AiProposalUndoService $undo): RedirectResponse
    {
        if ($proposal->ai_common_conversation_id !== $conversation->id) {
            abort(404);
        }
        $undo->undo($proposal, $request->user());

        return back()->with('status', 'Shared Proposal undo completed.');
    }

    public function remove(Request $request, AiCommonConversation $conversation, AiCommonSharedParticipant $participant, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $writer->remove($request->user(), $request->attributes->get('currentCompany'), $conversation, $participant);

        return back()->with('status', 'Participantを除外しました。');
    }

    public function leave(Request $request, AiCommonConversation $conversation, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $writer->leave($request->user(), $request->attributes->get('currentCompany'), $conversation);

        return redirect()->route('ai-common.index')->with('status', 'Shared Conversationから退出しました。');
    }

    public function requestOwnerTransfer(Request $request, AiCommonConversation $conversation, AiCommonSharedParticipant $participant, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $writer->requestOwnerTransfer(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $participant,
        );

        return back()->with('status', 'Owner交代の承諾を依頼しました。承諾までは現在Ownerを維持します。');
    }

    public function acceptOwnerTransfer(Request $request, AiCommonConversation $conversation, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $writer->acceptOwnerTransfer($request->user(), $request->attributes->get('currentCompany'), $conversation);

        return back()->with('status', 'Owner責任を承諾しました。');
    }

    public function archive(Request $request, AiCommonConversation $conversation, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $writer->archive($request->user(), $request->attributes->get('currentCompany'), $conversation);

        return back()->with('status', 'Shared ConversationをArchiveしました。');
    }
}
