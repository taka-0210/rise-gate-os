<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiProposal;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use App\Services\AiProposalApplier;

class AiProposalController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        $this->authorizeWorkspaceProject($request, $project);

        $proposals = $project->aiProposals()
            ->with(['requester', 'reviewer'])
            ->withCount('items')
            ->latest()
            ->paginate(20);

        return view('ai-proposals.index', [
            'project' => $project,
            'proposals' => $proposals,
            'statuses' => AiProposal::statuses(),
        ]);
    }

    public function show(Request $request, Project $project, AiProposal $aiProposal): View
    {
        $this->authorizeWorkspaceProject($request, $project);
        abort_unless($aiProposal->project_id === $project->id, 404);

        $aiProposal->load(['items', 'requester', 'reviewer']);

        $itemCounts = [
            'create' => $aiProposal->items->where('operation', 'create')->count(),
            'update' => $aiProposal->items->where('operation', 'update')->count(),
            'valid' => $aiProposal->items->where('validation_status', 'valid')->count(),
            'invalid' => $aiProposal->items->where('validation_status', 'invalid')->count(),
        ];

        return view('ai-proposals.show', [
            'project' => $project,
            'proposal' => $aiProposal,
            'statuses' => AiProposal::statuses(),
            'itemCounts' => $itemCounts,
            'canReview' => Gate::allows('update', $project),
        ]);
    }

    public function apply(Request $request, Project $project, AiProposal $aiProposal, AiProposalApplier $applier): RedirectResponse
    {
        $this->authorizeWorkspaceProject($request, $project);
        Gate::authorize('update', $project);
        abort_unless($aiProposal->project_id === $project->id, 404);

        $applier->apply($aiProposal, $request->user());

        return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])
            ->with('status', 'AI提案を承認し、本データへ反映しました。');
    }

    public function reject(Request $request, Project $project, AiProposal $aiProposal): RedirectResponse
    {
        $this->authorizeWorkspaceProject($request, $project);
        Gate::authorize('update', $project);
        abort_unless($aiProposal->project_id === $project->id, 404);
        abort_unless($aiProposal->status === AiProposal::STATUS_PENDING, 422);

        $aiProposal->update([
            'status' => AiProposal::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])
            ->with('status', 'AI提案を却下しました。');
    }

    private function authorizeWorkspaceProject(Request $request, Project $project): void
    {
        Gate::authorize('view', $project);
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        abort_unless($currentWorkspace && $project->owning_workspace_id === $currentWorkspace->id, 404);
    }
}
