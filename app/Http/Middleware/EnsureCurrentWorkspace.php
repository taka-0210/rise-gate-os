<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspaceId = $request->session()->get('current_workspace_id');

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $workspaceId || ! $user->canAccessWorkspace((int) $workspaceId)) {
            $workspace = $user->workspaces()->orderBy('workspaces.name')->first();

            if (! $workspace) {
                $request->session()->forget('current_workspace_id');
                return redirect()->route('workspaces.index');
            }

            $workspaceId = $workspace->id;
            $request->session()->put('current_workspace_id', $workspaceId);
        }

        $currentWorkspace = Workspace::query()
            ->with('organization')
            ->findOrFail($workspaceId);

        $membership = $user->workspaces()
            ->where('workspaces.id', $currentWorkspace->id)
            ->firstOrFail()
            ->pivot;

        $request->attributes->set('currentWorkspace', $currentWorkspace);
        $request->attributes->set('currentWorkspaceRole', $membership->role);

        View::share('currentWorkspace', $currentWorkspace);
        View::share('currentWorkspaceRole', $membership->role);

        return $next($request);
    }
}
