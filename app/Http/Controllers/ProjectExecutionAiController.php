<?php

namespace App\Http\Controllers;

use App\Models\AiProposal;
use App\Models\AiRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\AiProposalApprover;
use App\Services\AiProposalAuthorization;
use App\Services\AiProposalScopeOneApplier;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use App\Services\ProjectExecution\ProjectExecutionProposalContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProjectExecutionAiController extends Controller
{
    public function index(Request $request, Project $project, ProjectExecutionAccess $access): View
    {
        $this->authorizeProject($request, $project, $access);

        return view('project-execution.ai.index', [
            'project' => $project,
            'requests' => $project->aiRequests()->with('proposal')->latest()->limit(20)->get(),
            'proposals' => $project->aiProposals()
                ->where('contract_version', ProjectExecutionProposalContract::VERSION)
                ->withCount('items')->latest()->limit(20)->get(),
        ]);
    }

    public function storeRequest(Request $request, Project $project, ProjectExecutionAccess $access): RedirectResponse
    {
        $this->authorizeProject($request, $project, $access);
        abort_unless($access->canCreateAction($request->user(), $project), 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:5000'],
        ]);
        $aiRequest = $project->aiRequests()->create([
            'title' => $data['title'],
            'instructions' => implode("\n", [
                'Scope 8の実行計画をproject-action.v1で提案してください。',
                'Project Purpose: '.$project->purpose,
                'Expected Outcome: '.$project->expected_outcome,
                '',
                '依頼内容: '.$data['instructions'],
            ]),
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'requested_by' => $request->user()->id,
            'status' => AiRequest::STATUS_PENDING,
        ]);

        return redirect()->route('project-execution.ai.index', $project)
            ->with('status', 'AIへの実行計画依頼を登録しました。')
            ->with('created_ai_request', $aiRequest->public_id);
    }

    public function show(Request $request, Project $project, AiProposal $aiProposal, ProjectExecutionAccess $access, AiProposalAuthorization $authorization): View
    {
        $this->authorizeProject($request, $project, $access);
        $this->authorizeProposal($request, $project, $aiProposal, $authorization, false);
        $aiProposal->load(['items', 'requester', 'approver', 'applyAttempts.itemResults.item']);
        $userIds = $aiProposal->items->flatMap(fn ($item) => [
            $item->after['assigned_to'] ?? null,
            $item->after['reviewer_user_id'] ?? null,
        ])->filter()->unique();

        return view('project-execution.ai.show', [
            'project' => $project,
            'proposal' => $aiProposal,
            'users' => User::query()->whereIn('id', $userIds)->pluck('name', 'id'),
            'canReview' => $authorization->canReview($request->user(), $project, $aiProposal),
            'latestAttempt' => $aiProposal->applyAttempts->sortByDesc('attempt_number')->first(),
        ]);
    }

    public function requestRevision(Request $request, Project $project, AiProposal $aiProposal, ProjectExecutionAccess $access, AiProposalAuthorization $authorization): RedirectResponse
    {
        $this->authorizeProject($request, $project, $access);
        $this->authorizeProposal($request, $project, $aiProposal, $authorization);
        $data = $request->validate(['overall_feedback' => ['required', 'string', 'max:5000']]);

        DB::transaction(function () use ($request, $project, $aiProposal, $authorization, $data): void {
            $locked = AiProposal::query()->lockForUpdate()->with('items')->findOrFail($aiProposal->id);
            $this->authorizeProposal($request, $project, $locked, $authorization);
            abort_unless(in_array($locked->status, [AiProposal::STATUS_PENDING, AiProposal::STATUS_APPROVED], true), 422);
            $project->aiRequests()->create([
                'title' => '「'.$locked->title.'」の修正依頼',
                'instructions' => implode("\n", [
                    '元Proposal ID: '.$locked->public_id,
                    'Scope 8の実行計画をproject-action.v1で再提案してください。',
                    'Project Purpose: '.$project->purpose,
                    'Expected Outcome: '.$project->expected_outcome,
                    '修正内容: '.$data['overall_feedback'],
                ]),
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'requested_by' => $request->user()->id,
                'status' => AiRequest::STATUS_PENDING,
            ]);
            $locked->update([
                'status' => AiProposal::STATUS_REJECTED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'approved_content_hash' => null,
                'approved_project_version' => null,
            ]);
        });

        return redirect()->route('project-execution.ai.index', $project)
            ->with('status', '修正内容をAIへの再依頼として登録しました。');
    }

    public function approve(Request $request, Project $project, AiProposal $aiProposal, ProjectExecutionAccess $access, AiProposalAuthorization $authorization, AiProposalApprover $approver): RedirectResponse
    {
        $this->authorizeProject($request, $project, $access);
        $this->authorizeProposal($request, $project, $aiProposal, $authorization);
        $approver->approve($aiProposal, $request->user());

        return redirect()->route('project-execution.ai.proposals.show', [$project, $aiProposal])
            ->with('status', '提案内容を承認しました。まだProjectへは反映されていません。');
    }

    public function apply(Request $request, Project $project, AiProposal $aiProposal, ProjectExecutionAccess $access, AiProposalAuthorization $authorization, AiProposalScopeOneApplier $applier): RedirectResponse
    {
        $this->authorizeProject($request, $project, $access);
        $this->authorizeProposal($request, $project, $aiProposal, $authorization);
        try {
            $applier->apply($aiProposal, $request->user());
        } catch (ValidationException $error) {
            return redirect()->route('project-execution.ai.proposals.show', [$project, $aiProposal])
                ->withErrors($error->errors());
        }

        return redirect()->route('project-execution.ai.proposals.show', [$project, $aiProposal])
            ->with('status', 'AI提案をProjectへ反映しました。');
    }

    private function authorizeProject(Request $request, Project $project, ProjectExecutionAccess $access): void
    {
        $company = $request->attributes->get('currentCompany');
        abort_unless($project->usesScopeEight() && $company && $project->organization_id === $company->id, 404);
        abort_unless($access->activeExplicitMember($request->user(), $project), 403);
    }

    private function authorizeProposal(Request $request, Project $project, AiProposal $proposal, AiProposalAuthorization $authorization, bool $review = true): void
    {
        abort_unless($proposal->project_id === $project->id
            && $proposal->contract_version === ProjectExecutionProposalContract::VERSION, 404);
        $review
            ? $authorization->authorize($request->user(), $project, $proposal)
            : $authorization->authorizeView($request->user(), $project, $proposal);
    }
}
