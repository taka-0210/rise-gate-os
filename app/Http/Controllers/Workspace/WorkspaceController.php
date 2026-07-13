<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function index(Request $request): View
    {
        $workspaces = $request->user()
            ->workspaces()
            ->with('organization')
            ->orderBy('workspaces.name')
            ->get();

        return view('workspaces.index', [
            'workspaces' => $workspaces,
            'currentWorkspaceId' => $request->session()->get('current_workspace_id'),
        ]);
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless($request->user()->canAccessWorkspace($workspace->id), 403);

        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('dashboard');
    }
}
