<?php

namespace App\Http\Controllers;

use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\AiCommonSource;
use App\Models\AiCommonSourceRevision;
use App\Models\AiProposal;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Services\AiCommon\AiCommonAccess;
use App\Services\AiCommon\AiCommonConversationReader;
use App\Services\AiCommon\AiCommonGateway;
use App\Services\AiCommon\AiCommonGatewayException;
use App\Services\AiCommon\AiCommonProposalFactory;
use App\Services\AiCommon\AiCommonProposalLineage;
use App\Services\AiCommon\AiCommonSourceManifest;
use App\Services\AiProposalApplier;
use App\Services\AiProposalApprover;
use App\Services\AiProposalUndoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AiCommonController extends Controller
{
    public function index(Request $request, AiCommonAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        $membership = $access->authorizeOrganization($request->user(), $organization);

        return view('ai-common.index', [
            'conversations' => AiCommonConversation::query()->where('organization_id', $organization->id)
                ->where('user_id', $request->user()->id)->where('status', AiCommonConversation::STATUS_ACTIVE)
                ->latest('last_message_at')->latest('id')->get(),
            'archivedConversations' => AiCommonConversation::query()->where('organization_id', $organization->id)
                ->where('user_id', $request->user()->id)->where('status', AiCommonConversation::STATUS_ARCHIVED)
                ->latest('archived_at')->latest('id')->get(),
            'policy' => $access->policy($organization),
            'canManageOrganization' => $membership->organization_role === 'owner',
        ]);
    }

    public function store(Request $request, AiCommonAccess $access): RedirectResponse
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeCategory($request->user(), $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $validated = $request->validate(['title' => ['required', 'string', 'max:160']]);
        $conversation = AiCommonConversation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'title' => trim($validated['title']),
            'status' => AiCommonConversation::STATUS_ACTIVE,
            'version' => 1,
        ]);

        return redirect()->route('ai-common.show', $conversation);
    }

    public function show(
        Request $request,
        AiCommonConversation $conversation,
        AiCommonAccess $access,
        AiCommonConversationReader $reader,
        AiCommonProposalLineage $proposalLineage,
    ): View {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeConversation($request->user(), $organization, $conversation);
        $messageRows = $reader->visible($request->user(), $organization, $conversation);

        $conversation->load(['sources', 'proposals.items', 'proposals.applyAttempts', 'proposals.undos']);
        $visibleProposals = $conversation->proposals->filter(function (AiProposal $proposal) use ($request, $proposalLineage): bool {
            try {
                $proposalLineage->authorizeProposal($request->user(), $proposal);

                return true;
            } catch (Throwable) {
                return false;
            }
        });
        $conversation->setRelation('proposals', $visibleProposals->values());

        return view('ai-common.show', [
            'conversation' => $conversation,
            'messageRows' => $messageRows,
            'proposalSourceMessage' => collect($messageRows)->last(
                fn (array $row): bool => $row['visible']
                    && $row['message']->role === AiCommonMessage::ROLE_ASSISTANT
                    && $row['message']->source_lineage_version === AiCommonMessage::SOURCE_LINEAGE_V1
            )['message'] ?? null,
        ]);
    }

    public function message(Request $request, AiCommonConversation $conversation, AiCommonAccess $access, AiCommonConversationReader $reader, AiCommonSourceManifest $manifest, AiCommonGateway $gateway): RedirectResponse
    {
        $organization = $request->attributes->get('currentCompany');
        $access->authorizeCategory($request->user(), $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $access->authorizeConversation($request->user(), $organization, $conversation, true);
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:4000'],
            'source_ids' => ['nullable', 'array', 'max:20'],
            'source_ids.*' => ['integer'],
        ]);
        $sourceIds = array_map('intval', $validated['source_ids'] ?? []);
        $sources = $conversation->sources()->whereIn('id', $sourceIds)->get();
        if ($sources->count() !== count(array_unique($sourceIds))) {
            throw ValidationException::withMessages(['sources' => '選択したSourceを利用できません。']);
        }
        $manifest->revisionsForSelections($request->user(), $organization, $sources);
        $conversation->messages()->create([
            'role' => AiCommonMessage::ROLE_USER,
            'content' => trim($validated['content']),
            'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
        ]);
        $conversation->update(['last_message_at' => now(), 'version' => $conversation->version + 1]);
        try {
            $result = $gateway->respond(
                $request->user(),
                $conversation,
                [],
                [],
                authorizeAttempt: fn (): array => $this->authorizedAttemptContext(
                    $request,
                    $organization,
                    $conversation,
                    $sourceIds,
                    $access,
                    $reader,
                    $manifest,
                ),
            );
        } catch (AiCommonGatewayException $error) {
            return back()->withInput()->with('error', 'COへ接続できませんでした。入力は履歴に保持されています。通常業務は続けられます。');
        }
        $authorizedContext = $result['_authorized_context'];
        try {
            $this->authorizeResponseContext($request, $organization, $conversation, $authorizedContext, $access, $manifest);
        } catch (Throwable) {
            return back()->withInput()->with('error', 'The AI response was not published because source access changed during processing.');
        }
        $allowedHandles = collect($authorizedContext['sources'])->pluck('handle');
        if (collect($result['citations'])->diff($allowedHandles)->isNotEmpty()) {
            throw ValidationException::withMessages(['citation' => 'Providerが未選択Sourceを参照したため回答を保存しませんでした。']);
        }
        $assistant = $conversation->messages()->create([
            'role' => AiCommonMessage::ROLE_ASSISTANT,
            'content' => $result['answer'],
            'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
            'source_fingerprint' => hash('sha256', $allowedHandles->sort()->implode('|')),
            'source_lineage_version' => AiCommonMessage::SOURCE_LINEAGE_V1,
            'provider' => $result['provider'],
            'model' => $result['model'],
            'logical_request_id' => $result['logical_request_id'],
        ]);
        $assistant->sources()->sync($sources->pluck('id'));
        $assistant->sourceRevisions()->sync($authorizedContext['revision_ids']);
        $conversation->update(['last_message_at' => now(), 'version' => $conversation->version + 1]);

        return back()->with('status', 'COから回答が届きました。');
    }

    public function source(Request $request, AiCommonConversation $conversation, AiCommonSourceManifest $manifest): RedirectResponse
    {
        $validated = $request->validate([
            'resource_type' => ['required', 'string'],
            'resource_public_id' => ['required', 'string', 'max:64'],
            'selection_reason' => ['required', 'string', 'max:160'],
        ]);
        $manifest->select(
            $request->user(),
            $request->attributes->get('currentCompany'),
            $conversation,
            $validated['resource_type'],
            $validated['resource_public_id'],
            $validated['selection_reason'],
        );

        return back()->with('status', 'Contextを選択しました。');
    }

    public function proposal(Request $request, AiCommonConversation $conversation, AiCommonProposalFactory $factory): RedirectResponse
    {
        $validated = $request->validate([
            'operation' => ['required', 'string'],
            'target_public_id' => ['nullable', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'published_summary' => ['nullable', 'string', 'max:1000'],
            'source_message_public_id' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'attributes' => ['required', 'array'],
        ]);
        $factory->create($request->user(), $request->attributes->get('currentCompany'), $conversation, $validated);

        return back()->with('status', 'Proposalを作成しました。まだ適用されていません。');
    }

    public function approve(Request $request, AiCommonConversation $conversation, AiProposal $proposal, AiCommonAccess $access, AiProposalApprover $approver): RedirectResponse
    {
        $access->authorizeConversation($request->user(), $request->attributes->get('currentCompany'), $conversation, true);
        if ($proposal->ai_common_conversation_id !== $conversation->id) {
            abort(404);
        }
        $approver->approve($proposal, $request->user());

        return back()->with('status', 'Proposalを承認しました。まだ適用は完了していません。');
    }

    public function apply(Request $request, AiCommonConversation $conversation, AiProposal $proposal, AiCommonAccess $access, AiProposalApplier $applier): RedirectResponse
    {
        $access->authorizeConversation($request->user(), $request->attributes->get('currentCompany'), $conversation, true);
        if ($proposal->ai_common_conversation_id !== $conversation->id) {
            abort(404);
        }
        $applier->apply($proposal, $request->user());

        return back()->with('status', 'Writerの成功を確認し、変更を適用しました。');
    }

    public function undo(Request $request, AiCommonConversation $conversation, AiProposal $proposal, AiCommonAccess $access, AiProposalUndoService $undo): RedirectResponse
    {
        $access->authorizeConversation($request->user(), $request->attributes->get('currentCompany'), $conversation, true);
        if ($proposal->ai_common_conversation_id !== $conversation->id) {
            abort(404);
        }
        $undo->undo($proposal, $request->user());

        return back()->with('status', 'Writer経由で更新を元に戻しました。');
    }

    private function authorizedAttemptContext(
        Request $request,
        Organization $organization,
        AiCommonConversation $conversation,
        array $sourceIds,
        AiCommonAccess $access,
        AiCommonConversationReader $reader,
        AiCommonSourceManifest $manifest,
    ): array {
        $actor = $request->user()->fresh();
        $access->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $access->authorizeConversation($actor, $organization, $conversation->fresh(), true);
        $sources = $conversation->sources()->whereIn('id', $sourceIds)->get();
        if ($sources->count() !== count(array_unique($sourceIds))) {
            throw ValidationException::withMessages(['sources' => 'The selected source is no longer available.']);
        }
        $directRevisions = $manifest->revisionsForSelections($actor, $organization, $sources);
        $history = $reader->providerContext($actor, $organization, $conversation->fresh());
        $revisions = $directRevisions->concat($history['revisions'])->unique('id')->values();

        return [
            'messages' => $history['messages'],
            'sources' => $manifest->authorizedRevisions($actor, $organization, $revisions),
            'revision_ids' => $revisions->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'direct_selection_revision_map' => $sources->mapWithKeys(
                fn (AiCommonSource $source): array => [$source->id => (int) $source->current_revision_id]
            )->all(),
        ];
    }

    private function authorizeResponseContext(
        Request $request,
        Organization $organization,
        AiCommonConversation $conversation,
        array $context,
        AiCommonAccess $access,
        AiCommonSourceManifest $manifest,
    ): void {
        $actor = $request->user()->fresh();
        $access->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $access->authorizeConversation($actor, $organization, $conversation->fresh(), true);
        $revisionIds = array_values(array_unique(array_map('intval', $context['revision_ids'])));
        $revisions = AiCommonSourceRevision::query()
            ->where('ai_common_conversation_id', $conversation->id)
            ->whereIn('id', $revisionIds)
            ->get();
        if ($revisions->count() !== count($revisionIds)) {
            throw ValidationException::withMessages(['source' => 'Source lineage changed during processing.']);
        }
        $authorized = $manifest->authorizedRevisions($actor, $organization, $revisions);
        if (collect($authorized)->pluck('handle')->sort()->values()->all()
            !== collect($context['sources'])->pluck('handle')->sort()->values()->all()) {
            throw ValidationException::withMessages(['source' => 'Source authorization changed during processing.']);
        }
        $manifest->assertSelectionsStillPointTo($context['direct_selection_revision_map']);
    }

    public function archive(Request $request, AiCommonConversation $conversation, AiCommonAccess $access): RedirectResponse
    {
        $organization = $request->attributes->get('currentCompany');
        DB::transaction(function () use ($request, $organization, $conversation, $access): void {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $access->authorizeConversation($request->user(), $organization, $locked, true);
            $locked->update([
                'status' => AiCommonConversation::STATUS_ARCHIVED,
                'archived_at' => now(),
                'version' => $locked->version + 1,
            ]);
        }, 3);

        return redirect()->route('ai-common.index')->with('status', 'ConversationをArchiveしました。');
    }
}
