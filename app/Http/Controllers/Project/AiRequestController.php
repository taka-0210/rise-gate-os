<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AiRequestController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('view', $project);
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace && $project->owning_workspace_id === $workspace->id, 404);
        $validated = $request->validate([
            'title' => ['required','string','max:255'],
            'instructions' => ['required','string','max:10000'],
        ]);
        $project->aiRequests()->create($validated + [
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'requested_by' => $request->user()->id,
            'status' => AiRequest::STATUS_PENDING,
        ]);
        return back()->with('status', 'AIへの依頼を受け付けました。Codexが提案を返すまでお待ちください。');
    }
}
