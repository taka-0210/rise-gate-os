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
use Illuminate\Validation\ValidationException;

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
        $models = $query->with(match ($data['type']) {
            'roadmap' => 'improvements.tasks',
            'improvement' => 'tasks',
            'task' => [],
        })->get();
        $expectedIds = $models->pluck('id')->sort()->values()->all();
        $submittedIds = collect($data['ids'])->sort()->values()->all();
        abort_unless($expectedIds === $submittedIds, 422, '同じ階層内の項目だけを並び替えられます。');
        $models->each(fn ($model) => Gate::authorize('update', $model));
        $field = match ($data['type']) {
            'roadmap' => 'sort_order',
            'improvement' => 'roadmap_sort_order',
            'task' => 'sort_order',
        };

        $scheduleUpdated = DB::transaction(function () use ($models, $data, $field, $project): bool {
            $schedule = $this->buildSchedule($project, $data['type'], $models, $data['ids'], $data['parent_id']);
            $scheduleUpdated = false;
            foreach ($data['ids'] as $index => $id) {
                $model = $models->firstWhere('id', $id);
                $attributes = [$field => $index + 1];
                if (isset($schedule[$id])) {
                    [$start, $end] = $schedule[$id];
                    $oldStart = $model->planned_start_day;
                    $scheduleUpdated = $scheduleUpdated || $oldStart !== $start || $this->endDay($model, $data['type']) !== $end;
                    $attributes += $this->scheduleAttributes($project, $data['type'], $start, $end);
                    if ($oldStart !== $start) $this->shiftDescendants($project, $data['type'], $model, $start - $oldStart);
                }
                $model->update($attributes);
            }
            return $scheduleUpdated;
        });

        return response()->json(['message' => '表示順を保存しました。', 'schedule_updated' => $scheduleUpdated]);
    }

    private function buildSchedule(Project $project, string $type, $models, array $ids, ?int $parentId): array
    {
        if ($models->isEmpty() || $models->contains(fn ($model) => ! $model->planned_start_day || ! $this->endDay($model, $type))) return [];

        [$parentStart, $parentEnd] = match ($type) {
            'roadmap' => [1, $project->duration_days],
            'improvement' => [Roadmap::find($parentId)?->planned_start_day, Roadmap::find($parentId)?->target_day],
            'task' => [Improvement::find($parentId)?->planned_start_day, Improvement::find($parentId)?->target_day],
        };
        $parentStart = (int) ($parentStart ?: $models->min('planned_start_day'));
        $parentEnd = (int) ($parentEnd ?: $models->max(fn ($model) => $this->endDay($model, $type)));
        $cursor = max($parentStart, (int) $models->min('planned_start_day'));
        $schedule = [];
        foreach ($ids as $id) {
            $model = $models->firstWhere('id', $id);
            $duration = $this->endDay($model, $type) - $model->planned_start_day + 1;
            $schedule[$id] = [$cursor, $cursor + $duration - 1];
            $cursor += $duration;
        }
        if ($cursor - 1 > $parentEnd) {
            throw ValidationException::withMessages(['schedule' => '並び替え後の日程が親要素の期間を超えます。先に期間を調整してください。']);
        }
        return $schedule;
    }

    private function endDay($model, string $type): ?int
    {
        return $type === 'task' ? $model->due_day : $model->target_day;
    }

    private function scheduleAttributes(Project $project, string $type, int $start, int $end): array
    {
        $attributes = $type === 'task'
            ? ['planned_start_day' => $start, 'due_day' => $end]
            : ['planned_start_day' => $start, 'target_day' => $end];
        if ($project->start_date) {
            $attributes['planned_start_date'] = $project->start_date->copy()->addDays($start - 1);
            $attributes[$type === 'task' ? 'due_date' : 'target_date'] = $project->start_date->copy()->addDays($end - 1);
        }
        return $attributes;
    }

    private function shiftDescendants(Project $project, string $type, $model, int $delta): void
    {
        $children = match ($type) {
            'roadmap' => $model->improvements,
            'improvement' => $model->tasks,
            'task' => collect(),
        };
        foreach ($children as $child) {
            $childType = $type === 'roadmap' ? 'improvement' : 'task';
            if ($child->planned_start_day && $this->endDay($child, $childType)) {
                $child->update($this->scheduleAttributes($project, $childType, $child->planned_start_day + $delta, $this->endDay($child, $childType) + $delta));
                $this->shiftDescendants($project, $childType, $child, $delta);
            }
        }
    }
}
