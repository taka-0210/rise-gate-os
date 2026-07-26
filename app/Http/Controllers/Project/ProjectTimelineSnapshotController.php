<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectPlanVersion;
use App\Services\ProjectPlanSnapshotService;
use App\Services\ProjectPlanRestoreService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectTimelineSnapshotController extends Controller
{
    public function index(Project $project): View
    {
        Gate::authorize('view', $project);

        return view('projects.timeline-snapshots.index', [
            'project' => $project,
            'versions' => $project->planVersions()->with(['creator', 'sourceProposal'])->paginate(20),
            'canSave' => Gate::allows('update', $project),
        ]);
    }

    public function store(
        Request $request,
        Project $project,
        ProjectPlanSnapshotService $snapshots,
    ): RedirectResponse {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);

        $version = $snapshots->storeManualTimelineVersion(
            $project,
            $request->user(),
            trim($validated['title']),
            isset($validated['note']) ? trim($validated['note']) : null,
        );

        return redirect()
            ->route('projects.timeline-snapshots.show', [$project, $version])
            ->with('status', '現在のタイムラインを保存しました。');
    }

    public function show(Project $project, ProjectPlanVersion $timelineSnapshot): View
    {
        Gate::authorize('view', $project);
        abort_unless($timelineSnapshot->project_id === $project->id, 404);

        $snapshot = $timelineSnapshot->plan_snapshot;
        [$rows, $axis] = $this->timeline($snapshot);

        return view('projects.timeline-snapshots.show', [
            'project' => $project,
            'version' => $timelineSnapshot->load(['creator', 'sourceProposal']),
            'snapshot' => $snapshot,
            'rows' => $rows,
            'axis' => $axis,
        ]);
    }

    public function restoreConfirmation(
        Project $project,
        ProjectPlanVersion $timelineSnapshot,
        ProjectPlanRestoreService $restore,
    ): View {
        Gate::authorize('update', $project);
        abort_unless($timelineSnapshot->project_id === $project->id, 404);

        return view('projects.timeline-snapshots.restore', [
            'project' => $project,
            'version' => $timelineSnapshot,
            'changes' => $restore->preview($project, $timelineSnapshot),
        ]);
    }

    public function restore(
        Request $request,
        Project $project,
        ProjectPlanVersion $timelineSnapshot,
        ProjectPlanRestoreService $restore,
    ): RedirectResponse {
        Gate::authorize('update', $project);
        abort_unless($timelineSnapshot->project_id === $project->id, 404);
        $request->validate(['confirmed' => ['accepted']]);

        $restoredVersion = $restore->restore($project, $timelineSnapshot, $request->user());

        return redirect()
            ->route('projects.timeline-snapshots.show', [$project, $restoredVersion])
            ->with('status', '保存履歴から予定内容を復元しました。復元前の状態も自動保存しています。');
    }

    private function timeline(array $snapshot): array
    {
        $project = (array) ($snapshot['project'] ?? []);
        $relative = empty($project['start_date']) && (int) ($project['duration_days'] ?? 0) > 0;
        $rows = collect();
        $rows->push($this->row('project', $project, $project['name'] ?? 'Project', $relative, 'start_date', 'due_date', 1, 'duration_days'));

        foreach ((array) ($snapshot['roadmaps'] ?? []) as $roadmap) {
            $rows->push($this->row('roadmap', $roadmap, $roadmap['title'] ?? 'Roadmap', $relative, 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'));
            foreach ((array) ($roadmap['improvements'] ?? []) as $improvement) {
                $rows->push($this->row('improvement', $improvement, $improvement['title'] ?? '取り組み', $relative, 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'));
                foreach ((array) ($improvement['tasks'] ?? []) as $task) {
                    $rows->push($this->row('task', $task, $task['title'] ?? 'Task', $relative, 'planned_start_date', 'due_date', 'planned_start_day', 'due_day'));
                }
            }
        }

        foreach ((array) ($snapshot['unclassified_improvements'] ?? []) as $improvement) {
            $rows->push($this->row('improvement', $improvement, $improvement['title'] ?? '取り組み', $relative, 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'));
            foreach ((array) ($improvement['tasks'] ?? []) as $task) {
                $rows->push($this->row('task', $task, $task['title'] ?? 'Task', $relative, 'planned_start_date', 'due_date', 'planned_start_day', 'due_day'));
            }
        }

        $rows = $rows->filter(fn (array $row): bool => $row['start'] !== null || $row['end'] !== null)->values();
        if ($relative) {
            $axisStart = 1;
            $axisEnd = max(1, (int) $rows->flatMap(fn (array $row) => [$row['start'], $row['end']])->filter()->max());
        } else {
            $dates = $rows->flatMap(fn (array $row) => [$row['start'], $row['end']])->filter();
            $axisStart = $dates->min() ?: now()->startOfDay();
            $axisEnd = $dates->max() ?: $axisStart->copy()->addDay();
            if ($axisStart->equalTo($axisEnd)) $axisEnd = $axisEnd->copy()->addDay();
        }

        $span = $relative ? max(1, $axisEnd - $axisStart) : max(1, $axisStart->diffInDays($axisEnd));
        $rows = $rows->map(function (array $row) use ($relative, $axisStart, $span): array {
            $start = $row['start'] ?? $row['end'];
            $end = $row['end'] ?? $row['start'];
            $offset = $relative ? $start - $axisStart : $axisStart->diffInDays($start, false);
            $duration = $relative ? $end - $start : $start->diffInDays($end);
            $row['left'] = max(0, min(100, $offset / $span * 100));
            $row['width'] = max(.8, min(100 - $row['left'], max(1, $duration) / $span * 100));
            return $row;
        });

        return [$rows, [
            'relative' => $relative,
            'start' => $axisStart,
            'end' => $axisEnd,
        ]];
    }

    private function row(
        string $type,
        array $data,
        string $title,
        bool $relative,
        string $dateStart,
        string $dateEnd,
        int|string $dayStart,
        string $dayEnd,
    ): array {
        if ($relative) {
            $start = is_int($dayStart) ? $dayStart : ($data[$dayStart] ?? null);
            $end = $data[$dayEnd] ?? null;
        } else {
            $start = ! empty($data[$dateStart]) ? Carbon::parse($data[$dateStart])->startOfDay() : null;
            $end = ! empty($data[$dateEnd]) ? Carbon::parse($data[$dateEnd])->startOfDay() : null;
        }

        return compact('type', 'title', 'start', 'end');
    }
}
