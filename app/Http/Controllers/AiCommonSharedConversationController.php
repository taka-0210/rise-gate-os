<?php

namespace App\Http\Controllers;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedParticipant;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\AiCommon\AiCommonHumanMessageWriter;
use App\Services\AiCommon\AiCommonSharedAccess;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
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

    public function show(Request $request, AiCommonConversation $conversation, AiCommonSharedAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $participant = $access->authorizeParticipant($request->user(), $organization, $conversation);
        $shared = $participant->sharedConversation;
        $shared->load([
            'owner', 'pendingOwner', 'currentPurposeRevision', 'purposeRevisions.createdBy',
            'participants.user',
        ]);
        $conversation->load(['messages.sharedAuthor.author']);

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

    public function invite(Request $request, AiCommonConversation $conversation, AiCommonSharedConversationWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'invitee_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $writer->invite(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            User::query()->findOrFail($validated['invitee_user_id']),
            $validated,
        );

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

    public function humanMessage(Request $request, AiCommonConversation $conversation, AiCommonHumanMessageWriter $writer): RedirectResponse
    {
        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'content' => ['required', 'string', 'max:4000'],
        ]);
        $writer->postShared(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $validated,
        );

        return back()->with('status', 'Human Messageを保存しました。AI Requestは送信していません。');
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
