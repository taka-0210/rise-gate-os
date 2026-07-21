<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Improvement;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ImprovementEffortController extends Controller
{
    public function update(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        $validated = $request->validate([
            'efforts' => ['required', 'array', 'max:200'],
            'efforts.*' => ['nullable', 'numeric', 'min:0.25', 'max:999.99'],
        ]);

        $ids = collect(array_keys($validated['efforts']))->map(fn ($id) => (int) $id)->filter()->values();
        $improvements = $project->improvements()->whereIn('id', $ids)->get()->keyBy('id');
        if ($improvements->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['efforts' => 'このProjectに属さない取り組みが含まれています。']);
        }

        DB::transaction(function () use ($request, $validated, $improvements): void {
            foreach ($validated['efforts'] as $id => $effort) {
                $improvement = $improvements->get((int) $id);
                Gate::forUser($request->user())->authorize('update', $improvement);
                $improvement->update(['planned_effort_days' => $effort === null || $effort === '' ? null : $effort]);
            }
        });

        return redirect()->route('projects.show', ['project' => $project, 'view' => 'time', 'effort_editor' => 1])
            ->with('status', '取り組みの予定工数を一括更新しました。');
    }
}
