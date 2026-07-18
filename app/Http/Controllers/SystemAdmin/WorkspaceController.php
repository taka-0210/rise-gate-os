<?php

namespace App\Http\Controllers\SystemAdmin;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class WorkspaceController extends Controller
{
    public function index(): View
    {
        return view('system-admin.workspaces.index', [
            'workspaces' => Workspace::query()
                ->with(['organization', 'owner'])
                ->withCount('users')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function edit(Workspace $workspace): View
    {
        return view('system-admin.workspaces.edit', ['workspace' => $workspace->load(['organization', 'owner'])]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:255']]);
        $workspace->update(['name' => $validated['name']]);

        return redirect()->route('system-admin.workspaces.edit', $workspace)->with('status', 'Workspace名を更新しました。');
    }

    public function updateStatus(Request $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Workspace::STATUS_PENDING,
                Workspace::STATUS_ACTIVE,
                Workspace::STATUS_SUSPENDED,
            ])],
        ]);
        $workspace->update(['status' => $validated['status']]);

        return redirect()->route('system-admin.workspaces.edit', $workspace)->with('status', 'Workspaceの利用状態を更新しました。');
    }
}
