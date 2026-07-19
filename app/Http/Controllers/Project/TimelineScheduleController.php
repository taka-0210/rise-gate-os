<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Services\ScheduleIntegrityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TimelineScheduleController extends Controller
{
    public function update(Request $request, Project $project, string $type, int $entity): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless(in_array($type, ['roadmap', 'improvement', 'task'], true), 404);

        $model = match ($type) {
            'roadmap' => $project->roadmaps()->findOrFail($entity),
            'improvement' => $project->improvements()->findOrFail($entity),
            'task' => $project->tasks()->findOrFail($entity),
        };
        Gate::authorize('update', $model);

        $attributes = match ($type) {
            'roadmap', 'improvement' => $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'cascade_move' => ['sometimes', 'boolean'],
                'cascade_children' => ['sometimes', 'boolean'],
                'cascade_anchor' => ['sometimes', 'in:start,end'],
            ]),
            'task' => $request->validate([
                'end_date' => ['required', 'date'],
                'cascade_move' => ['sometimes', 'boolean'],
            ]),
        };

        $integrity = DB::transaction(function () use ($project, $type, $model, $attributes): array {
            $before = app(ScheduleIntegrityService::class)->inspect($project->fresh())['invalid'];

            if (($attributes['cascade_move'] ?? false) && in_array($type, ['roadmap', 'improvement'], true)) {
                $newStart = Carbon::parse($attributes['start_date'])->startOfDay();
                $dayDelta = $model->planned_start_date->copy()->startOfDay()->diffInDays($newStart, false);
                $expectedEnd = $model->target_date->copy()->addDays($dayDelta)->toDateString();

                if ($attributes['end_date'] !== $expectedEnd) {
                    throw ValidationException::withMessages([
                        'schedule' => '親要素の移動では期間の長さを変更できません。左右端を使って期間を調整してください。',
                    ]);
                }

                $this->moveDescendants($type, $model, $dayDelta);
            }

            if (($attributes['cascade_children'] ?? false) && in_array($type, ['roadmap', 'improvement'], true)) {
                $anchor = $attributes['cascade_anchor'] ?? 'end';
                $originalDate = $anchor === 'start' ? $model->planned_start_date : $model->target_date;
                $changedDate = Carbon::parse($attributes[$anchor.'_date'])->startOfDay();
                $dayDelta = $originalDate->copy()->startOfDay()->diffInDays($changedDate, false);
                $this->moveDescendants($type, $model, $dayDelta);
            }

            $model->update(match ($type) {
                'roadmap', 'improvement' => [
                    'planned_start_date' => $attributes['start_date'],
                    'target_date' => $attributes['end_date'],
                ],
                'task' => ['due_date' => $attributes['end_date']],
            });
            $after = app(ScheduleIntegrityService::class)->inspect($project->fresh());
            $newInvalid = $after['invalid']->diff($before);
            if ($newInvalid->isNotEmpty()
                && ! ($attributes['cascade_move'] ?? false)
                && ! ($attributes['cascade_children'] ?? false)) {
                throw ValidationException::withMessages(['schedule' => $newInvalid->first()]);
            }
            return $after;
        });

        return response()->json(['message' => '日程を更新しました。', 'integrity' => $integrity]);
    }

    private function moveDescendants(string $type, Roadmap|Improvement $model, int $dayDelta): void
    {
        if ($dayDelta === 0) {
            return;
        }

        $improvements = $type === 'roadmap'
            ? $model->improvements()->with('tasks')->get()
            : collect([$model->load('tasks')]);

        foreach ($improvements as $improvement) {
            if ($type === 'roadmap') {
                $improvement->update([
                    'planned_start_date' => $improvement->planned_start_date?->copy()->addDays($dayDelta),
                    'target_date' => $improvement->target_date?->copy()->addDays($dayDelta),
                ]);
            }

            foreach ($improvement->tasks as $task) {
                if ($task->due_date) {
                    $task->update(['due_date' => $task->due_date->copy()->addDays($dayDelta)]);
                }
            }
        }
    }
}
