<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProjectHandoffController extends Controller
{
    public function index(Project $project): View
    {
        Gate::authorize('view', $project);

        return view('project-handoffs.index', [
            'project' => $project,
            'latest' => $project->handoffs()
                ->where('status', ProjectHandoff::STATUS_APPROVED)
                ->with(['proposer', 'reviewer'])
                ->latest('reviewed_at')
                ->first(),
            'pending' => $project->handoffs()
                ->where('status', ProjectHandoff::STATUS_PENDING)
                ->with('proposer')
                ->latest()
                ->get(),
            'history' => $project->handoffs()
                ->where('status', ProjectHandoff::STATUS_APPROVED)
                ->with(['proposer', 'reviewer'])
                ->latest('reviewed_at')
                ->paginate(20),
            'canUpdate' => Gate::allows('update', $project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $validated = $this->validateHandoff($request);

        $project->handoffs()->create($validated + [
            'source' => ProjectHandoff::SOURCE_MANUAL,
            'status' => ProjectHandoff::STATUS_APPROVED,
            'proposed_by' => $request->user()->id,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', '引継ぎを更新しました。');
    }

    public function approve(Request $request, Project $project, ProjectHandoff $handoff): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->ensurePendingHandoff($project, $handoff);

        DB::transaction(function () use ($request, $handoff): void {
            $locked = ProjectHandoff::query()->lockForUpdate()->findOrFail($handoff->id);
            if ($locked->status !== ProjectHandoff::STATUS_PENDING) {
                throw ValidationException::withMessages(['handoff' => 'この提案はすでに処理されています。']);
            }
            $locked->update([
                'status' => ProjectHandoff::STATUS_APPROVED,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        return back()->with('status', 'AIの引継ぎ提案を承認しました。');
    }

    public function reject(Request $request, Project $project, ProjectHandoff $handoff): RedirectResponse
    {
        Gate::authorize('update', $project);
        $this->ensurePendingHandoff($project, $handoff);

        $handoff->update([
            'status' => ProjectHandoff::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return back()->with('status', 'AIの引継ぎ提案を見送りました。');
    }

    private function validateHandoff(Request $request): array
    {
        return $request->validate([
            'completed_work' => ['required', 'string', 'max:10000'],
            'next_work' => ['required', 'string', 'max:10000'],
        ], [
            'completed_work.required' => '「前回作業ではここまで」を入力してください。',
            'next_work.required' => '「次回作業はここから」を入力してください。',
        ]);
    }

    private function ensurePendingHandoff(Project $project, ProjectHandoff $handoff): void
    {
        abort_unless($handoff->project_id === $project->id, 404);
        if ($handoff->status !== ProjectHandoff::STATUS_PENDING) {
            throw ValidationException::withMessages(['handoff' => 'この提案はすでに処理されています。']);
        }
    }
}
