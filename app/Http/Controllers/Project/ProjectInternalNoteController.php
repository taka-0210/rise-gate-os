<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectInternalNote;
use App\Models\ProjectMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProjectInternalNoteController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorizeInternalMember($request, $project);
        Gate::authorize('update', $project);
        $validated = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $project->internalNotes()->create($validated + ['user_id' => $request->user()->id]);

        return redirect()->route('projects.show', $project)->with('status', '社内メモを追加しました。');
    }

    public function destroy(Request $request, Project $project, ProjectInternalNote $internalNote): RedirectResponse
    {
        $this->authorizeInternalMember($request, $project);
        Gate::authorize('update', $project);
        abort_unless($internalNote->project_id === $project->id, 404);
        $internalNote->delete();

        return redirect()->route('projects.show', $project)->with('status', '社内メモを削除しました。');
    }

    private function authorizeInternalMember(Request $request, Project $project): void
    {
        Gate::authorize('view', $project);
        $role = $project->members()->where('user_id', $request->user()->id)->where('status', ProjectMember::STATUS_ACTIVE)->value('project_role');
        if ($role === ProjectMember::ROLE_CLIENT) {
            throw new HttpException(403);
        }
    }
}
