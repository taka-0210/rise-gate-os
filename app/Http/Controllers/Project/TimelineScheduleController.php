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
        abort_unless(in_array($type, ['project', 'roadmap', 'improvement', 'task'], true), 404);

        $model = match ($type) {
            'project' => $entity === $project->id ? $project : abort(404),
            'roadmap' => $project->roadmaps()->findOrFail($entity),
            'improvement' => $project->improvements()->findOrFail($entity),
            'task' => $project->tasks()->findOrFail($entity),
        };
        Gate::authorize('update', $model);

        $attributes = match ($type) {
            'project', 'roadmap', 'improvement' => $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'cascade_move' => ['sometimes', 'boolean'],
                'cascade_children' => ['sometimes', 'boolean'],
                'cascade_anchor' => ['sometimes', 'in:start,end'],
                'reset_descendants' => ['sometimes', 'boolean'],
            ]),
            'task' => $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'cascade_move' => ['sometimes', 'boolean'],
            ]),
        };

        $integrity = DB::transaction(function () use ($project, $type, $model, $attributes): array {
            $before = app(ScheduleIntegrityService::class)->inspect($project->fresh())['invalid'];
            $projectBoundaryBefore = $this->projectBoundaryViolations($project->fresh());

            if (($attributes['reset_descendants'] ?? false) && in_array($type, ['project', 'roadmap', 'improvement'], true)) {
                $this->resetDescendantSchedules($type, $model);
            }

            if (! ($attributes['reset_descendants'] ?? false)
                && ($attributes['cascade_move'] ?? false)
                && in_array($type, ['project', 'roadmap', 'improvement'], true)) {
                $newStart = Carbon::parse($attributes['start_date'])->startOfDay();
                $dayDelta = $this->scheduleStart($type, $model)->copy()->startOfDay()->diffInDays($newStart, false);
                $expectedEnd = $this->scheduleEnd($type, $model)->copy()->addDays($dayDelta)->toDateString();

                if ($attributes['end_date'] !== $expectedEnd) {
                    throw ValidationException::withMessages([
                        'schedule' => '親要素の移動では期間の長さを変更できません。左右端を使って期間を調整してください。',
                    ]);
                }

                $this->moveDescendants($type, $model, $dayDelta);
            }

            if (! ($attributes['reset_descendants'] ?? false)
                && ($attributes['cascade_children'] ?? false)
                && in_array($type, ['project', 'roadmap', 'improvement'], true)) {
                $anchor = $attributes['cascade_anchor'] ?? 'end';
                $originalDate = $anchor === 'start'
                    ? $this->scheduleStart($type, $model)
                    : $this->scheduleEnd($type, $model);
                $changedDate = Carbon::parse($attributes[$anchor.'_date'])->startOfDay();
                $dayDelta = $originalDate->copy()->startOfDay()->diffInDays($changedDate, false);
                $this->moveDescendants($type, $model, $dayDelta);
            }

            $model->update(match ($type) {
                'project' => [
                    'start_date' => $attributes['start_date'],
                    'due_date' => $attributes['end_date'],
                ],
                'roadmap', 'improvement' => [
                    'planned_start_date' => $attributes['start_date'],
                    'target_date' => $attributes['end_date'],
                ],
                'task' => [
                    'planned_start_date' => $attributes['start_date'],
                    'due_date' => $attributes['end_date'],
                ],
            });

            $projectBoundaryAfter = $this->projectBoundaryViolations($project->fresh());
            $newBoundaryViolation = $projectBoundaryAfter->diffKeys($projectBoundaryBefore)->first();
            if ($newBoundaryViolation) {
                throw ValidationException::withMessages(['schedule' => $newBoundaryViolation]);
            }

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

    private function moveDescendants(string $type, Project|Roadmap|Improvement $model, int $dayDelta): void
    {
        if ($dayDelta === 0) {
            return;
        }

        if ($type === 'project') {
            foreach ($model->roadmaps()->with('improvements.tasks')->get() as $roadmap) {
                $roadmap->update([
                    'planned_start_date' => $roadmap->planned_start_date?->copy()->addDays($dayDelta),
                    'target_date' => $roadmap->target_date?->copy()->addDays($dayDelta),
                ]);
                $this->moveDescendants('roadmap', $roadmap, $dayDelta);
            }

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
                    $task->update([
                        'planned_start_date' => $task->planned_start_date?->copy()->addDays($dayDelta),
                        'due_date' => $task->due_date->copy()->addDays($dayDelta),
                    ]);
                }
            }
        }
    }

    private function resetDescendantSchedules(string $type, Project|Roadmap|Improvement $model): void
    {
        if ($type === 'project') {
            $model->roadmaps()->update(['planned_start_date' => null, 'target_date' => null]);
            $model->improvements()->update(['planned_start_date' => null, 'target_date' => null]);
            $model->tasks()->update(['planned_start_date' => null, 'due_date' => null]);

            return;
        }

        if ($type === 'roadmap') {
            $improvementIds = $model->improvements()->pluck('id');
            $model->improvements()->update(['planned_start_date' => null, 'target_date' => null]);
            Task::query()->whereIn('improvement_id', $improvementIds)->update(['planned_start_date' => null, 'due_date' => null]);

            return;
        }

        $model->tasks()->update(['planned_start_date' => null, 'due_date' => null]);
    }

    private function scheduleStart(string $type, Project|Roadmap|Improvement $model): Carbon
    {
        return ($type === 'project' ? $model->start_date : $model->planned_start_date)->copy();
    }

    private function scheduleEnd(string $type, Project|Roadmap|Improvement $model): Carbon
    {
        return ($type === 'project' ? $model->due_date : $model->target_date)->copy();
    }

    private function projectBoundaryViolations(Project $project): \Illuminate\Support\Collection
    {
        $violations = collect();
        if (! $project->start_date || ! $project->due_date) {
            return $violations;
        }

        $outsideRoadmaps = $project->roadmaps()
            ->where(function ($query) use ($project) {
                $query->whereDate('planned_start_date', '<', $project->start_date)
                    ->orWhereDate('target_date', '>', $project->due_date);
            })
            ->get();

        foreach ($outsideRoadmaps as $outside) {
            $violations->put(
                'roadmap:'.$outside->id,
                "ロードマップ「{$outside->title}」がプロジェクト期間外になるため保存できません。プロジェクト期間内へ移動してください。"
            );
        }

        $outsideImprovements = $project->improvements()
            ->where(function ($query) use ($project) {
                $query->whereDate('planned_start_date', '<', $project->start_date)
                    ->orWhereDate('target_date', '>', $project->due_date);
            })
            ->get();

        foreach ($outsideImprovements as $outsideImprovement) {
            $violations->put(
                'improvement:'.$outsideImprovement->id,
                "取り組み「{$outsideImprovement->title}」がプロジェクト期間外になるため保存できません。プロジェクト期間内へ移動してください。"
            );
        }

        $outsideTasks = $project->tasks()
            ->where(function ($query) use ($project) {
                $query->whereDate('planned_start_date', '<', $project->start_date)
                    ->orWhereDate('due_date', '>', $project->due_date);
            })
            ->get();

        foreach ($outsideTasks as $outsideTask) {
            $violations->put(
                'task:'.$outsideTask->id,
                "タスク「{$outsideTask->title}」がプロジェクト期間外になるため保存できません。期限をプロジェクト期間内へ移動してください。"
            );
        }

        return $violations;
    }
}
