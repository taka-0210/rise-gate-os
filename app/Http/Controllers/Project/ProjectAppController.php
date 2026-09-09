<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectApp;
use App\Models\ProjectAppAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ProjectAppController extends Controller
{
    private function authorizeApp(Request $request, Project $project, ?ProjectApp $app = null, bool $edit = false): void
    {
        Gate::authorize($edit ? 'update' : 'view', $project);
        abort_unless($request->attributes->get('currentWorkspace')?->id === $project->owning_workspace_id, 404);
        abort_if($app && $app->project_id !== $project->id, 404);
    }

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorizeApp($request, $project);

        return response()->json(['apps' => ProjectApp::where('project_id', $project->id)->orderBy('name')->get()
            ->map(fn (ProjectApp $app): array => $this->summary($project, $app))])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorizeApp($request, $project, edit: true);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'html' => ['required_without:template', 'nullable', 'string', 'max:1000000'],
            'template' => ['nullable', 'in:todo'],
            'admin_login' => ['required', 'string', 'regex:/^[a-zA-Z0-9_.-]+$/', 'max:80'],
            'admin_password' => ['required', 'string', 'min:8', 'max:200'],
        ]);
        $html = ($input['template'] ?? null) === 'todo'
            ? file_get_contents(resource_path('project-apps/todo.html')) : $input['html'];
        abort_if(strlen($html) > 1_000_000, 422, 'アプリは1MB以内にしてください。');
        $app = DB::transaction(function () use ($project, $request, $input, $html): ProjectApp {
            $app = ProjectApp::create([
                'project_id' => $project->id, 'created_by' => $request->user()->id,
                'name' => $input['name'], 'html' => $html, 'version' => 1,
            ]);
            ProjectAppAccount::create([
                'project_app_id' => $app->id, 'login' => strtolower($input['admin_login']),
                'name' => '管理者', 'password' => $input['admin_password'], 'role' => 'admin', 'enabled' => true,
            ]);

            return $app;
        });

        return response()->json(['app' => $this->summary($project, $app)], 201);
    }

    public function source(Request $request, Project $project, ProjectApp $projectApp): JsonResponse
    {
        $this->authorizeApp($request, $project, $projectApp, true);

        return response()->json(['app' => [...$this->summary($project, $projectApp), 'html' => $projectApp->html]])
            ->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Project $project, ProjectApp $projectApp): JsonResponse
    {
        $this->authorizeApp($request, $project, $projectApp, true);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'html' => ['required', 'string', 'max:1000000'],
            'version' => ['required', 'integer', 'min:1'],
            'original_hash' => ['nullable', 'string', 'size:64'],
        ]);
        abort_if(strlen($input['html']) > 1_000_000, 422, 'アプリは1MB以内にしてください。');
        if (! empty($input['original_hash'])) {
            $normalized = str_replace(["\r\n", "\r"], "\n", $projectApp->html);
            abort_unless(hash_equals(hash('sha256', $normalized), $input['original_hash']), 409, 'AI提案後にソースが更新されています。最新ソースで再度依頼してください。');
        }
        $updated = ProjectApp::whereKey($projectApp->id)->where('version', $input['version'])->update([
            'name' => $input['name'], 'html' => $input['html'],
            'version' => $input['version'] + 1, 'updated_at' => now(),
        ]);
        abort_unless($updated, 409, '他の画面でアプリが更新されました。最新のソースを開き直してください。');

        return response()->json(['app' => $this->summary($project, $projectApp->fresh())]);
    }

    private function summary(Project $project, ProjectApp $app): array
    {
        return [
            'id' => $app->public_id, 'name' => $app->name, 'version' => $app->version,
            'run_url' => route('apps.run', $app),
            'source_url' => route('projects.apps.source', [$project, $app]),
            'update_url' => route('projects.apps.update', [$project, $app]),
        ];
    }
}
