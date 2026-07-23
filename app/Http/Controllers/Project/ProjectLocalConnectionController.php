<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectLocalConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectLocalConnectionController extends Controller
{
    public function store(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        $data = $request->validate([
            'directory_name' => ['required', 'string', 'max:255'],
            'local_site_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);
        ProjectLocalConnection::updateOrCreate(
            ['project_id' => $project->id, 'user_id' => $request->user()->id],
            $data + ['local_path' => 'browser-directory-handle', 'status' => 'configured', 'last_connected_at' => null],
        );

        return back()->with('status', 'このPCのローカルフォルダ設定を保存しました。');
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);
        ProjectLocalConnection::query()->where('project_id', $project->id)->where('user_id', $request->user()->id)->delete();

        return back()->with('status', 'ローカルフォルダ設定を解除しました。');
    }
}
