<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiProposal;
use App\Models\AiProposalItemReview;
use App\Models\AiRequest;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\AiProposalApprover;
use App\Services\AiProposalAuthorization;
use App\Services\AiProposalOutlineBuilder;
use App\Services\AiProposalScopeOneApplier;
use App\Services\AiProposalUndoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AiProposalController extends Controller
{
    public function index(Request $request, Project $project, AiProposalAuthorization $authorization): View
    {
        $this->authorizeWorkspaceProject($request, $project);
        $authorization->authorizeView($request->user(), $project);

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

    public function show(Request $request, Project $project, AiProposal $aiProposal, AiProposalOutlineBuilder $outlineBuilder, AiProposalAuthorization $authorization): View
    {
        $this->authorizeWorkspaceProject($request, $project);
        abort_unless($aiProposal->project_id === $project->id, 404);
        $authorization->authorizeView($request->user(), $project, $aiProposal);

        $aiProposal->load([
            'items.review.mergeTarget', 'items.review.reviewer', 'requester', 'reviewer',
            'applyAttempts.itemResults.item', 'undos',
        ]);
        $proposalOutline = $outlineBuilder->build($project, $aiProposal);

        $itemCounts = [
            'create' => $aiProposal->items->where('operation', 'create')->count(),
            'update' => $aiProposal->items->where('operation', 'update')->count(),
            'delete' => $aiProposal->items->where('operation', 'delete')->count(),
            'valid' => $aiProposal->items->where('validation_status', 'valid')->count(),
            'invalid' => $aiProposal->items->where('validation_status', 'invalid')->count(),
            'project' => $aiProposal->items->where('entity_type', 'project')->count(),
            'roadmap' => count($proposalOutline),
            'improvement' => collect($proposalOutline)->sum(fn (array $roadmap) => count($roadmap['improvements'])),
            'task' => collect($proposalOutline)->sum(fn (array $roadmap) => collect($roadmap['improvements'])->sum(fn (array $improvement) => count($improvement['tasks']))),
        ];

        $validItems = $aiProposal->items->where('validation_status', 'valid');
        $currentEntityCounts = [
            'roadmap' => $project->roadmaps()->count(),
            'improvement' => $project->improvements()->count(),
            'task' => $project->tasks()->count(),
        ];
        $impactCounts = collect($currentEntityCounts)->mapWithKeys(function (int $current, string $entityType) use ($aiProposal, $validItems): array {
            if ($aiProposal->replacesTimeline()) {
                $replacementCount = $validItems
                    ->where('entity_type', $entityType)
                    ->where('operation', 'create')
                    ->count();
                $isApplied = $aiProposal->status === AiProposal::STATUS_APPLIED;
                $before = $isApplied
                    ? $this->snapshotEntityCount(
                        $aiProposal->appliedPlanVersion?->plan_snapshot ?? [],
                        $entityType,
                    )
                    : $current;

                return [$entityType => [
                    'before' => $before,
                    'after' => $isApplied ? $current : $replacementCount,
                    'delta' => ($isApplied ? $current : $replacementCount) - $before,
                ]];
            }

            $delta = $validItems
                ->where('entity_type', $entityType)
                ->sum(fn ($item): int => match ($item->operation) {
                    'create' => 1,
                    'delete' => -1,
                    default => 0,
                });
            $isApplied = $aiProposal->status === AiProposal::STATUS_APPLIED;

            return [$entityType => [
                'before' => $isApplied ? max(0, $current - $delta) : $current,
                'after' => $isApplied ? $current : max(0, $current + $delta),
                'delta' => $delta,
            ]];
        })->all();

        return view('ai-proposals.show', [
            'project' => $project,
            'proposal' => $aiProposal,
            'statuses' => AiProposal::statuses(),
            'itemCounts' => $itemCounts,
            'canReview' => $authorization->canReview($request->user(), $project, $aiProposal),
            'proposalOutline' => $proposalOutline,
            'impactCounts' => $impactCounts,
            'reviewActions' => AiProposalItemReview::actions(),
            'unresolvedReviewCount' => $aiProposal->items->pluck('review')->filter()->whereNull('resolved_at')->count(),
            'projectMetadataItem' => $aiProposal->items->firstWhere('entity_type', 'project'),
            'proposalModes' => AiProposal::modes(),
        ]);
    }

    private function snapshotEntityCount(array $snapshot, string $entityType): int
    {
        $roadmaps = collect($snapshot['roadmaps'] ?? []);
        $unclassified = collect($snapshot['unclassified_improvements'] ?? []);

        return match ($entityType) {
            'roadmap' => $roadmaps->count(),
            'improvement' => $roadmaps->sum(
                fn (array $roadmap): int => count($roadmap['improvements'] ?? [])
            ) + $unclassified->count(),
            'task' => $roadmaps->sum(fn (array $roadmap): int => collect($roadmap['improvements'] ?? [])
                ->sum(fn (array $improvement): int => count($improvement['tasks'] ?? [])))
                + $unclassified->sum(fn (array $improvement): int => count($improvement['tasks'] ?? [])),
            default => 0,
        };
    }

    public function approve(Request $request, Project $project, AiProposal $aiProposal, AiProposalApprover $approver): RedirectResponse
    {
        $this->authorizeWorkspaceProject($request, $project);
        abort_unless($aiProposal->project_id === $project->id, 404);

        if ($aiProposal->items()->whereHas('review', fn ($query) => $query->whereNull('resolved_at'))->exists()) {
            throw ValidationException::withMessages([
                'reviews' => '未対応の項目別レビューがあります。AIに再提案を依頼してから承認してください。',
            ]);
        }

        try {
            $approver->approve($aiProposal, $request->user());
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])
                ->withErrors(['proposal' => '一時的な障害で内容確認を記録できませんでした。変更は反映されていません。']);
        }

        return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])
            ->with('status', '提案内容の確認を記録しました。続けて変更を適用してください。');
    }

    public function apply(Request $request, Project $project, AiProposal $aiProposal, AiProposalScopeOneApplier $applier): RedirectResponse
    {
        $this->authorizeWorkspaceProject($request, $project);
        abort_unless($aiProposal->project_id === $project->id, 404);
        if ($aiProposal->status === AiProposal::STATUS_PENDING) {
            throw ValidationException::withMessages(['proposal' => '先に提案内容を確認してください。']);
        }
        if ($failure = $this->applySafely($applier, $aiProposal, $request)) {
            return $failure;
        }

        return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])->with('status', 'AI提案を本データへ反映しました。');
    }

    private function applySafely(AiProposalScopeOneApplier $applier, AiProposal $proposal, Request $request): ?RedirectResponse
    {
        try {
            $applier->apply($proposal, $request->user());

            return null;
        } catch (ValidationException $error) {
            throw $error;
        } catch (Throwable $error) {
            report($error);

            return redirect()->route('projects.ai-proposals.show', [$proposal->project, $proposal])
                ->withErrors(['proposal' => '一時的な障害で変更を反映できませんでした。変更は一件も反映されていません。']);
        }
    }

    public function undo(Request $request, Project $project, AiProposal $aiProposal, AiProposalUndoService $undo): RedirectResponse
    {
        $this->authorizeWorkspaceProject($request, $project);
        abort_unless($aiProposal->project_id === $project->id, 404);
        $undo->undo($aiProposal, $request->user());

        return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])->with('status', 'Scope 1で変更した説明項目を元の値へ復元しました。');
    }

    public function reject(Request $request, Project $project, AiProposal $aiProposal, AiProposalAuthorization $authorization): RedirectResponse
    {
        $this->authorizeWorkspaceProject($request, $project);
        abort_unless($aiProposal->project_id === $project->id, 404);
        DB::transaction(function () use ($request, $project, $aiProposal, $authorization): void {
            $locked = AiProposal::query()->lockForUpdate()->with(['project', 'items'])->findOrFail($aiProposal->id);
            $authorization->authorize($request->user(), $project, $locked);
            abort_unless($locked->status === AiProposal::STATUS_PENDING, 422);

            $locked->update([
                'status' => AiProposal::STATUS_REJECTED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
            $locked->aiRequest?->update(['status' => AiRequest::STATUS_CANCELLED, 'completed_at' => now()]);
        });

        return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])
            ->with('status', 'AI提案を承認待ちから外しました。');
    }

    public function handoff(Request $request, Project $project, AiProposal $aiProposal): RedirectResponse
    {
        $this->authorizeWorkspaceProject($request, $project);
        Gate::authorize('update', $project);
        abort_unless($aiProposal->project_id === $project->id, 404);
        abort_unless($aiProposal->status === AiProposal::STATUS_APPLIED, 422);

        if (! $aiProposal->handed_off_at) {
            $aiProposal->update([
                'handed_off_by' => $request->user()->id,
                'handed_off_at' => now(),
            ]);
        }

        return redirect()->route('projects.ai-proposals.show', [$project, $aiProposal])
            ->with('status', 'Codexへ作業開始を伝えたことを記録しました。');
    }

    private function authorizeWorkspaceProject(Request $request, Project $project): void
    {
        Gate::authorize('view', $project);
        $currentWorkspace = $request->attributes->get('currentWorkspace');
        if (! $currentWorkspace || $project->owning_workspace_id !== $currentWorkspace->id) {
            $workspace = $request->user()->workspaces()
                ->where('workspaces.id', $project->owning_workspace_id)
                ->where('workspaces.status', Workspace::STATUS_ACTIVE)
                ->first();
            abort_unless($workspace, 404);
            $request->session()->put('current_workspace_id', $workspace->id);
            $request->session()->put('access_mode', 'workspace');
            $request->attributes->set('currentWorkspace', $workspace);
            $request->attributes->set('currentWorkspaceRole', $workspace->pivot->role);
        }
    }
}
