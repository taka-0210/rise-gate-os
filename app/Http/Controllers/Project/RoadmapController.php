<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoadmapController extends Controller
{
    public function create(Project $project): View
    {
        Gate::authorize('view', $project);
        Gate::authorize('create', [Roadmap::class, $project]);

        return view('roadmaps.create', ['project' => $project, 'statuses' => Roadmap::statuses()]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        Gate::authorize('create', [Roadmap::class, $project]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'planned_start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'planned_start_day' => ['nullable', 'integer', 'min:1', 'lte:target_day'],
            'target_day' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reached_at' => ['nullable', 'date'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Roadmap::statuses()))],
            'position_after_roadmap_id' => [
                'nullable',
                Rule::exists('roadmaps', 'id')
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $this->ensureWithinProject($project, $validated);

        DB::transaction(function () use ($project, $request, $validated): void {
            $orderedRoadmaps = $project->roadmaps()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $insertIndex = 0;

            if ($validated['position_after_roadmap_id'] ?? null) {
                $afterIndex = $orderedRoadmaps->search(fn (Roadmap $roadmap) => $roadmap->id === (int) $validated['position_after_roadmap_id']);
                $insertIndex = $afterIndex === false ? $orderedRoadmaps->count() : $afterIndex + 1;
            }

            $orderedRoadmaps->each(function (Roadmap $roadmap, int $index) use ($insertIndex): void {
                $roadmap->update(['sort_order' => $index >= $insertIndex ? $index + 2 : $index + 1]);
            });

            Roadmap::create([
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'project_id' => $project->id,
                'title' => $validated['title'],
                'purpose' => $validated['purpose'] ?? null,
                'planned_start_date' => $validated['planned_start_date'] ?? null,
                'target_date' => $validated['target_date'] ?? null,
                'planned_start_day' => $validated['planned_start_day'] ?? null,
                'target_day' => $validated['target_day'] ?? null,
                'reached_at' => $validated['reached_at'] ?? null,
                'status' => $validated['status'],
                'sort_order' => $insertIndex + 1,
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('projects.show', $project)->with('status', 'ロードマップテーマを作成しました。');
    }

    public function edit(Project $project, Roadmap $roadmap): View
    {
        $this->authorizeProjectRoadmap($project, $roadmap);
        Gate::authorize('view', $project);
        Gate::authorize('update', $roadmap);

        return view('roadmaps.edit', [
            'project' => $project,
            'roadmap' => $roadmap,
            'statuses' => Roadmap::statuses(),
        ]);
    }

    public function update(Request $request, Project $project, Roadmap $roadmap): RedirectResponse
    {
        $this->authorizeProjectRoadmap($project, $roadmap);
        Gate::authorize('view', $project);
        Gate::authorize('update', $roadmap);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'planned_start_date' => ['nullable', 'date'],
            'target_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
            'planned_start_day' => ['nullable', 'integer', 'min:1', 'lte:target_day'],
            'target_day' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reached_at' => ['nullable', 'date'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Roadmap::statuses()))],
        ]);

        $this->ensureWithinProject($project, $validated);
        $this->ensureImprovementsRemainWithin($roadmap, $validated);
        $roadmap->update($validated);

        return redirect()->route('projects.show', $project)->with('status', 'Roadmapを更新しました。');
    }

    public function destroy(Project $project, Roadmap $roadmap): RedirectResponse
    {
        $this->authorizeProjectRoadmap($project, $roadmap);
        Gate::authorize('delete', $roadmap);
        if ($roadmap->improvements()->exists()) {
            return back()->withErrors(['delete' => '配下に取り組みがあるため削除できません。先に取り組みを移動または削除してください。']);
        }
        $roadmap->delete();

        return redirect()->route('projects.show', $project)->with('status', 'ロードマップを削除しました。');
    }

    public function assignImprovement(Request $request, Project $project, Improvement $improvement): RedirectResponse
    {
        $this->authorizeProjectImprovement($project, $improvement);

        Gate::authorize('view', $project);
        Gate::authorize('update', $improvement);

        $validated = $request->validate([
            'roadmap_id' => [
                'required',
                Rule::exists('roadmaps', 'id')
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

        $roadmap = Roadmap::where('project_id', $project->id)->findOrFail($validated['roadmap_id']);
        Gate::authorize('update', $roadmap);

        if ($improvement->planned_start_date && $improvement->target_date && $roadmap->planned_start_date && $roadmap->target_date
            && ($improvement->planned_start_date->lt($roadmap->planned_start_date) || $improvement->target_date->gt($roadmap->target_date))) {
            throw ValidationException::withMessages([
                'roadmap_id' => "この取り組みの期間は、ロードマップの予定期間（{$roadmap->planned_start_date->format('Y/m/d')}〜{$roadmap->target_date->format('Y/m/d')}）内で設定してください。",
            ]);
        }

        $maxSortOrder = (int) $roadmap->improvements()->max('roadmap_sort_order');

        $improvement->update([
            'roadmap_id' => $roadmap->id,
            'roadmap_sort_order' => $maxSortOrder + 1,
        ]);

        return redirect()->route('projects.show', $project)->with('status', 'ImprovementをRoadmapへ追加しました。');
    }

    public function removeImprovement(Project $project, Improvement $improvement): RedirectResponse
    {
        $this->authorizeProjectImprovement($project, $improvement);

        Gate::authorize('view', $project);
        Gate::authorize('update', $improvement);

        if ($improvement->roadmap) {
            Gate::authorize('update', $improvement->roadmap);
        }

        $improvement->update([
            'roadmap_id' => null,
            'roadmap_sort_order' => null,
        ]);

        return redirect()->route('projects.show', $project)->with('status', 'Improvementを未分類に戻しました。');
    }

    private function authorizeProjectImprovement(Project $project, Improvement $improvement): void
    {
        abort_unless($improvement->project_id === $project->id, 404);
    }

    private function authorizeProjectRoadmap(Project $project, Roadmap $roadmap): void
    {
        abort_unless($roadmap->project_id === $project->id, 404);
    }

    private function ensureWithinProject(Project $project, array $attributes): void
    {
        if (! $project->start_date && $project->duration_days && ! empty($attributes['planned_start_day']) && ! empty($attributes['target_day'])) {
            if ($attributes['planned_start_day'] < 1 || $attributes['target_day'] > $project->duration_days) {
                throw ValidationException::withMessages(['planned_start_day' => "ロードマップ期間はProjectの1日目〜{$project->duration_days}日目の範囲内で設定してください。"]);
            }
            return;
        }
        if (! $project->start_date || ! $project->due_date || empty($attributes['planned_start_date']) || empty($attributes['target_date'])) {
            return;
        }

        if ($attributes['planned_start_date'] < $project->start_date->toDateString() || $attributes['target_date'] > $project->due_date->toDateString()) {
            throw ValidationException::withMessages([
                'planned_start_date' => "ロードマップの期間は、Projectの予定期間（{$project->start_date->format('Y/m/d')}〜{$project->due_date->format('Y/m/d')}）内で設定してください。",
            ]);
        }
    }

    private function ensureImprovementsRemainWithin(Roadmap $roadmap, array $attributes): void
    {
        if (! empty($attributes['planned_start_day']) && ! empty($attributes['target_day'])) {
            $count = $roadmap->improvements()->where(fn ($query) => $query
                ->where('planned_start_day', '<', $attributes['planned_start_day'])
                ->orWhere('target_day', '>', $attributes['target_day']))->count();
            if ($count > 0) {
                throw ValidationException::withMessages(['planned_start_day' => "この変更により、取り組み{$count}件がロードマップ期間外になります。"]);
            }
            return;
        }
        if (empty($attributes['planned_start_date']) || empty($attributes['target_date'])) {
            return;
        }

        $count = $roadmap->improvements()
            ->whereNotNull('planned_start_date')
            ->whereNotNull('target_date')
            ->where(fn ($query) => $query
                ->whereDate('planned_start_date', '<', $attributes['planned_start_date'])
                ->orWhereDate('target_date', '>', $attributes['target_date']))
            ->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'planned_start_date' => "この変更により、取り組み{$count}件がロードマップの期間外になります。先に取り組みの日程を調整してください。",
            ]);
        }
    }
}
