<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAccessKey;
use App\Models\AiAuditLog;
use App\Models\Project;
use App\Services\AiMcpToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiProjectController extends Controller
{
    public function index(Request $request, AiMcpToolService $tools): JsonResponse
    {
        $key = $this->key($request);
        $projects = $tools->listProjects($key)['projects'];
        AiAuditLog::create([
            'workspace_id' => $key->workspace_id,
            'user_id' => $key->user_id,
            'ai_access_key_id' => $key->id,
            'event' => 'api.project_list.read',
            'succeeded' => true,
            'metadata' => ['resource_ids' => ['projects' => array_column($projects, 'public_id')]],
            'occurred_at' => now(),
        ]);

        return response()->json([
            'workspace' => ['public_id' => $key->workspace->public_id, 'name' => $key->workspace->name],
            'member' => $key->user ? ['id' => $key->user->id, 'name' => $key->user->name] : null,
            'projects' => $projects,
        ]);
    }

    public function show(Request $request, string $projectPublicId, AiMcpToolService $tools): JsonResponse
    {
        $key = $this->key($request);
        $context = $tools->getProjectPlan($key, $projectPublicId);
        $projectId = Project::query()->where('owning_workspace_id', $key->workspace_id)
            ->where('public_id', $projectPublicId)->value('id');
        AiAuditLog::create([
            'workspace_id' => $key->workspace_id,
            'user_id' => $key->user_id,
            'ai_access_key_id' => $key->id,
            'project_id' => $projectId,
            'event' => 'api.project_plan.read',
            'succeeded' => true,
            'metadata' => ['resource_ids' => ['project' => $projectPublicId]],
            'occurred_at' => now(),
        ]);

        return response()->json(['project' => $context]);
    }

    private function key(Request $request): AiAccessKey
    {
        return $request->attributes->get('aiAccessKey')->loadMissing(['workspace', 'user']);
    }
}
