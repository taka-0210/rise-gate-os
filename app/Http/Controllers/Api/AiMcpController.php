<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAccessKey;
use App\Models\AiAuditLog;
use App\Models\AiProposal;
use App\Models\Project;
use App\Services\AiMcpToolService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiMcpController extends Controller
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function handle(Request $request, AiMcpToolService $tools): JsonResponse
    {
        if (! $this->validOrigin($request)) {
            return $this->error(null, -32000, '許可されていないOriginです。', 403);
        }

        $message = $request->json()->all();
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? null;

        if (($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($method)) {
            return $this->error($id, -32600, 'Invalid Request');
        }

        if ($method === 'notifications/initialized') {
            return response()->json([], 202);
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'rise-gate-os', 'version' => '0.1.0'],
                'instructions' => '参加中Projectの計画を読み、変更は必ず承認待ち提案として送信してください。',
            ]),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->callTool($id, $message['params'] ?? [], $request->attributes->get('aiAccessKey'), $tools),
            default => $this->error($id, -32601, 'Method not found'),
        };
    }

    public function info(Request $request): JsonResponse
    {
        if (! $this->validOrigin($request)) {
            return $this->error(null, -32000, '許可されていないOriginです。', 403);
        }

        return response()->json([
            'name' => 'rise-gate-os',
            'transport' => 'streamable-http',
            'protocolVersion' => self::PROTOCOL_VERSION,
        ]);
    }

    private function callTool(mixed $id, array $params, AiAccessKey $key, AiMcpToolService $tools): JsonResponse
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];
        $startedAt = hrtime(true);

        try {
            $data = match ($name) {
                'list_projects' => $tools->listProjects($key),
                'get_project_plan' => $this->getProjectPlan($tools, $key, $arguments),
                'list_ai_requests' => $tools->listAiRequests($key),
                'claim_ai_request' => $tools->claimAiRequest($key, Validator::validate($arguments, ['request_public_id' => ['required', 'string']])['request_public_id']),
                'get_ai_request_attachment' => $this->getAiRequestAttachment($tools, $key, $arguments),
                'submit_proposal' => $this->submitProposal($tools, $key, $arguments),
                default => throw new \InvalidArgumentException('存在しないツールです。'),
            };

            $this->audit($key, (string) $name, $arguments, $data, true, null, $startedAt);

            $content = $data['_mcp_content'] ?? [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]];
            unset($data['_mcp_content']);

            return $this->result($id, [
                'content' => $content,
                'structuredContent' => $data,
                'isError' => false,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $message = $exception instanceof ValidationException
                ? implode("\n", $exception->validator->errors()->all())
                : ($exception instanceof ModelNotFoundException ? '対象データが見つかりません。' : $exception->getMessage());

            $this->audit($key, (string) $name, $arguments, null, false, $message, $startedAt);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $message]],
                'isError' => true,
            ]);
        }
    }

    private function audit(AiAccessKey $key, string $toolName, array $arguments, mixed $result, bool $succeeded, ?string $error, int $startedAt): void
    {
        $projectPublicId = $arguments['project_public_id'] ?? null;
        $projectId = $projectPublicId
            ? Project::query()->where('owning_workspace_id', $key->workspace_id)->where('public_id', $projectPublicId)->value('id')
            : null;
        $proposalId = is_array($result) && isset($result['proposal_id'])
            ? AiProposal::query()->where('public_id', $result['proposal_id'])->value('id')
            : null;

        AiAuditLog::create([
            'workspace_id' => $key->workspace_id,
            'user_id' => $key->user_id,
            'ai_access_key_id' => $key->id,
            'project_id' => $projectId,
            'ai_proposal_id' => $proposalId,
            'event' => 'mcp.tool_called',
            'tool_name' => $toolName,
            'succeeded' => $succeeded,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'request_fingerprint' => hash('sha256', json_encode([$toolName, $arguments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'error_message' => $error ? mb_substr($error, 0, 1000) : null,
            'metadata' => [
                'operation_count' => is_array($arguments['items'] ?? null) ? count($arguments['items']) : null,
                'idempotency_key' => $arguments['idempotency_key'] ?? null,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function getProjectPlan(AiMcpToolService $tools, AiAccessKey $key, array $arguments): array
    {
        $validated = Validator::validate($arguments, ['project_public_id' => ['required', 'string']]);

        return $tools->getProjectPlan($key, $validated['project_public_id']);
    }

    private function submitProposal(AiMcpToolService $tools, AiAccessKey $key, array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'project_public_id' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'evidence' => ['nullable', 'array'],
            'ai_request_public_id' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.operation' => ['required', Rule::in(['create', 'update', 'delete'])],
            'items.*.entity_type' => ['required', Rule::in(['roadmap', 'improvement', 'task'])],
            'items.*.target_public_id' => ['nullable', 'string'],
            'items.*.reference_key' => ['nullable', 'string', 'max:120'],
            'items.*.parent_reference' => ['nullable', 'string', 'max:120'],
            'items.*.attributes' => ['required', 'array'],
        ]);

        return $tools->submitProposal($key, $validated);
    }

    private function getAiRequestAttachment(AiMcpToolService $tools, AiAccessKey $key, array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'request_public_id' => ['required', 'string'],
            'attachment_public_id' => ['required', 'string'],
        ]);

        return $tools->getAiRequestAttachment($key, $validated['request_public_id'], $validated['attachment_public_id']);
    }

    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'list_projects',
                'title' => '参加Project一覧',
                'description' => '接続メンバーが現在のWorkspaceで参加しているProject一覧と件数を取得します。',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            ],
            [
                'name' => 'get_project_plan',
                'title' => 'Project計画と進捗',
                'description' => '指定ProjectのRoadmap、取り組み、Task、状態、優先度を取得します。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => ['project_public_id' => ['type' => 'string', 'description' => 'Projectのpublic_id']],
                    'required' => ['project_public_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            ],
            [
                'name' => 'submit_proposal',
                'title' => '承認待ち提案を送信',
                'description' => '計画の追加や進捗更新を本データへ直接反映せず、承認待ち提案として送信します。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_public_id' => ['type' => 'string'],
                        'idempotency_key' => ['type' => 'string'],
                        'title' => ['type' => 'string'],
                        'summary' => ['type' => ['string', 'null']],
                        'evidence' => ['type' => ['object', 'null'], 'additionalProperties' => true],
                        'ai_request_public_id' => ['type' => ['string', 'null']],
                        'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => [
                            'type' => 'object',
                            'properties' => [
                                'operation' => ['type' => 'string', 'enum' => ['create', 'update', 'delete']],
                                'entity_type' => ['type' => 'string', 'enum' => ['roadmap', 'improvement', 'task']],
                                'target_public_id' => ['type' => ['string', 'null']],
                                'reference_key' => ['type' => ['string', 'null']],
                                'parent_reference' => ['type' => ['string', 'null']],
                                'attributes' => ['type' => 'object', 'additionalProperties' => true],
                            ],
                            'required' => ['operation', 'entity_type', 'attributes'],
                            'additionalProperties' => false,
                        ]],
                    ],
                    'required' => ['project_public_id', 'idempotency_key', 'title', 'items'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
            ],
            [
                'name' => 'list_ai_requests',
                'title' => '未処理のAI依頼一覧',
                'description' => '現在のWorkspaceで、参加中Projectから届いた未処理のAI依頼を取得します。',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass, 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            ],
            [
                'name' => 'claim_ai_request',
                'title' => 'AI依頼を引き受ける',
                'description' => '未処理依頼をこのCodex接続が処理中として安全に確保します。',
                'inputSchema' => ['type' => 'object', 'properties' => ['request_public_id' => ['type' => 'string']], 'required' => ['request_public_id'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true],
            ],
            [
                'name' => 'get_ai_request_attachment',
                'title' => 'AI依頼の添付資料を取得',
                'description' => '権限のあるAI依頼に添付された画像・PDF・Excel・CSV・Wordを1件取得します。画像とCSVは直接読める形式で返します。',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'request_public_id' => ['type' => 'string'],
                        'attachment_public_id' => ['type' => 'string'],
                    ],
                    'required' => ['request_public_id', 'attachment_public_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true],
            ],
        ];
    }

    private function validOrigin(Request $request): bool
    {
        $origin = $request->header('Origin');
        if (! $origin) {
            return true;
        }
        $appUrl = rtrim((string) config('app.url'), '/');

        return hash_equals($appUrl, rtrim($origin, '/'));
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private function error(mixed $id, int $code, string $message, int $status = 400): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
