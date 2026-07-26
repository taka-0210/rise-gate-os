<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectActual;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProjectActualController extends Controller
{
    public function index(Request $request, Project $project): View
    {
        Gate::authorize('view', $project);

        $project->load([
            'roadmaps' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
            'roadmaps.improvements' => fn ($query) => $query->orderBy('roadmap_sort_order')->orderBy('id'),
            'roadmaps.improvements.tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        $actuals = $project->actuals()->with('recorder')->get();
        $displayView = $request->query('view') === 'time' ? 'time' : 'focus';
        $datedActuals = $actuals
            ->filter(fn (ProjectActual $actual) => $actual->actual_started_at || $actual->actual_completed_at)
            ->map(function (ProjectActual $actual): array {
                $start = ($actual->actual_started_at ?: $actual->actual_completed_at)->copy()->startOfDay();
                $end = ($actual->actual_completed_at ?: $actual->actual_started_at)->copy()->startOfDay();
                if ($end->lt($start)) {
                    [$start, $end] = [$end, $start];
                }

                return ['actual' => $actual, 'start' => $start, 'end' => $end];
            })
            ->sortBy('start')
            ->values();
        $axisStart = $datedActuals->min('start');
        $axisEnd = $datedActuals->max('end');
        if ($axisStart && $axisEnd && $axisEnd->lte($axisStart)) {
            $axisEnd = $axisStart->copy()->addDay();
        }
        $axisDays = $axisStart && $axisEnd ? max(1, $axisStart->diffInDays($axisEnd)) : 1;
        $timelineRows = $datedActuals->map(function (array $row) use ($axisStart, $axisDays): array {
            $left = $axisStart->diffInDays($row['start']) / $axisDays * 100;
            $duration = max(1, $row['start']->diffInDays($row['end']) + 1);

            return $row + [
                'left' => max(0, min(100, $left)),
                'width' => max(1.5, min(100 - $left, $duration / ($axisDays + 1) * 100)),
            ];
        });

        return view('projects.actuals.index', [
            'project' => $project,
            'actualsByTask' => $actuals
                ->where('related_entity_type', 'task')
                ->groupBy('related_entity_public_id'),
            'unplannedActuals' => $actuals->filter(
                fn (ProjectActual $actual) => ! $actual->related_entity_type || ! $actual->related_entity_public_id
            ),
            'actualCount' => $actuals->count(),
            'actualEffortMinutes' => (int) $actuals->sum('effort_minutes'),
            'displayView' => $displayView,
            'timelineRows' => $timelineRows,
            'axisStart' => $axisStart,
            'axisEnd' => $axisEnd,
            'canEdit' => Gate::allows('update', $project),
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'task_public_id' => ['nullable', 'string', 'max:26'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'result' => ['nullable', 'string', 'max:10000'],
            'actual_started_at' => ['nullable', 'date'],
            'actual_completed_at' => ['nullable', 'date', 'after_or_equal:actual_started_at'],
            'effort_hours' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'status' => ['required', Rule::in([ProjectActual::STATUS_RECORDED, ProjectActual::STATUS_CONFIRMED])],
        ]);

        $task = null;
        if ($validated['task_public_id'] ?? null) {
            $task = $project->tasks()->where('public_id', $validated['task_public_id'])->firstOrFail();
        }

        if (! $task && blank($validated['title'] ?? null)) {
            return back()->withErrors(['title' => '計画外の実績には作業名が必要です。'])->withInput();
        }

        ProjectActual::create([
            'project_id' => $project->id,
            'related_entity_type' => $task ? 'task' : null,
            'related_entity_public_id' => $task?->public_id,
            'title' => $task?->title ?? $validated['title'],
            'description' => $validated['description'] ?? null,
            'result' => $validated['result'] ?? null,
            'actual_started_at' => $validated['actual_started_at'] ?? null,
            'actual_completed_at' => $validated['actual_completed_at'] ?? null,
            'effort_minutes' => isset($validated['effort_hours'])
                ? (int) round(((float) $validated['effort_hours']) * 60)
                : null,
            'status' => $validated['status'],
            'recorded_by' => $request->user()->id,
        ]);

        return redirect()->route('projects.actuals.index', $project)
            ->with('status', '実績を登録しました。予定は変更されていません。');
    }

    public function destroy(Project $project, ProjectActual $actual): RedirectResponse
    {
        Gate::authorize('update', $project);
        abort_unless($actual->project_id === $project->id, 404);

        $actual->delete();

        return redirect()->route('projects.actuals.index', $project)
            ->with('status', '実績を削除しました。予定は変更されていません。');
    }
}
