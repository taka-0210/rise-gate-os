<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectMemberController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        $currentWorkspaceRole = $request->attributes->get('currentWorkspaceRole');
        Gate::authorize('manageMembers', [$project, $currentWorkspaceRole]);

        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'project_role' => ['required', Rule::in(array_keys(ProjectMember::roles()))],
            'permission_level' => ['required', Rule::in(array_keys(ProjectMember::permissions()))],
        ]);

        $user = User::where('email', $validated['email'])->firstOrFail();

        if ($project->members()->where('user_id', $user->id)->exists()) {
            return back()->withErrors(['email' => 'This user is already a project member.'])->withInput();
        }

        $memberWorkspace = $user->workspaces()->orderBy('workspaces.name')->first();

        if (! $memberWorkspace) {
            return back()->withErrors(['email' => 'This user does not belong to a workspace.'])->withInput();
        }

        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $memberWorkspace->id,
            'project_role' => $validated['project_role'],
            'permission_level' => $validated['permission_level'],
            'invited_by' => $request->user()->id,
            'invited_at' => now(),
            'accepted_at' => now(),
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        return redirect()->route('projects.show', $project)->with('status', 'Member added.');
    }

    public function destroy(Request $request, Project $project, ProjectMember $projectMember): RedirectResponse
    {
        $currentWorkspaceRole = $request->attributes->get('currentWorkspaceRole');
        Gate::authorize('manageMembers', [$project, $currentWorkspaceRole]);

        abort_unless($projectMember->project_id === $project->id, 404);

        if ($projectMember->user_id === $project->owner_user_id) {
            return back()->withErrors(['member' => 'Project owner cannot be removed.']);
        }

        $projectMember->delete();

        return redirect()->route('projects.show', $project)->with('status', 'Member removed.');
    }
}
