<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Services\ScheduleIntegrityService;
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
            ]),
            'task' => $request->validate(['end_date' => ['required', 'date']]),
        };

        $integrity = DB::transaction(function () use ($project, $type, $model, $attributes): array {
            $before = app(ScheduleIntegrityService::class)->inspect($project->fresh())['invalid'];
            $model->update(match ($type) {
                'roadmap', 'improvement' => [
                    'planned_start_date' => $attributes['start_date'],
                    'target_date' => $attributes['end_date'],
                ],
                'task' => ['due_date' => $attributes['end_date']],
            });
            $after = app(ScheduleIntegrityService::class)->inspect($project->fresh());
            $newInvalid = $after['invalid']->diff($before);
            if ($newInvalid->isNotEmpty()) {
                throw ValidationException::withMessages(['schedule' => $newInvalid->first()]);
            }
            return $after;
        });

        return response()->json(['message' => '日程を更新しました。', 'integrity' => $integrity]);
    }
}
