<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class WorkspaceOrderController extends Controller
{
    public function update(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $data = $request->validate([
            'type' => ['required', Rule::in(['roadmap', 'improvement', 'task'])],
            'parent_id' => ['nullable', 'integer'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $query = match ($data['type']) {
            'roadmap' => Roadmap::query()->where('project_id', $project->id),
            'improvement' => Improvement::query()->where('project_id', $project->id)->where('roadmap_id', $data['parent_id']),
            'task' => Task::query()->where('project_id', $project->id)->where('improvement_id', $data['parent_id']),
        };
        $models = $query->get();
        $expectedIds = $models->pluck('id')->sort()->values()->all();
        $submittedIds = collect($data['ids'])->sort()->values()->all();
        abort_unless($expectedIds === $submittedIds, 422, '同じ階層内の項目だけを並び替えられます。');
        $models->each(fn ($model) => Gate::authorize('update', $model));
        $field = match ($data['type']) {
            'roadmap' => 'sort_order',
            'improvement' => 'roadmap_sort_order',
            'task' => 'sort_order',
        };

        DB::transaction(function () use ($models, $data, $field): void {
            foreach ($data['ids'] as $index => $id) {
                $models->firstWhere('id', $id)->update([$field => $index + 1]);
            }
        });

        return response()->json(['message' => '表示順を保存しました。']);
    }
}
