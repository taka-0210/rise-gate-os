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

class RoadmapController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        Gate::authorize('create', [Roadmap::class, $project]);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', 'in:'.implode(',', array_keys(Roadmap::statuses()))],
            'position_after_roadmap_id' => [
                'nullable',
                Rule::exists('roadmaps', 'id')
                    ->where('project_id', $project->id)
                    ->whereNull('deleted_at'),
            ],
        ]);

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
                'status' => $validated['status'],
                'sort_order' => $insertIndex + 1,
                'created_by' => $request->user()->id,
            ]);
        });

        return redirect()->route('projects.show', $project)->with('status', 'ロードマップテーマを作成しました。');
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
}
