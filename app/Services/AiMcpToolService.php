<?php

namespace App\Services;

use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AiMcpToolService
{
    public function __construct(private readonly AiProposalValidator $validator) {}

    public function listProjects(AiAccessKey $key): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);

        return ['projects' => $this->visibleProjects($key)
            ->withCount(['roadmaps', 'improvements', 'tasks'])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'public_id' => $project->public_id,
                'name' => $project->name,
                'summary' => $project->summary,
                'status' => $project->status,
                'priority' => $project->priority,
                'roadmaps_count' => $project->roadmaps_count,
                'improvements_count' => $project->improvements_count,
                'tasks_count' => $project->tasks_count,
            ])->all()];
    }

    public function getProjectPlan(AiAccessKey $key, string $publicId): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);
        $project = $this->visibleProjects($key)
            ->where('public_id', $publicId)
            ->with(['roadmaps.improvements.tasks'])
            ->firstOrFail();

        return [
            'public_id' => $project->public_id,
            'name' => $project->name,
            'summary' => $project->summary,
            'current_state' => $project->current_state,
            'desired_future_state' => $project->desired_future_state,
            'status' => $project->status,
            'priority' => $project->priority,
            'start_date' => $project->start_date?->toDateString(),
            'due_date' => $project->due_date?->toDateString(),
            'duration_days' => $project->duration_days,
            'roadmaps' => $project->roadmaps->map(fn ($roadmap) => [
                'public_id' => $roadmap->public_id,
                'title' => $roadmap->title,
                'purpose' => $roadmap->purpose,
                'status' => $roadmap->status,
                'planned_start_date' => $roadmap->planned_start_date?->toDateString(),
                'target_date' => $roadmap->target_date?->toDateString(),
                'planned_start_day' => $roadmap->planned_start_day,
                'target_day' => $roadmap->target_day,
                'improvements' => $roadmap->improvements->map(fn ($improvement) => [
                    'public_id' => $improvement->public_id,
                    'title' => $improvement->title,
                    'status' => $improvement->status,
                    'next_action' => $improvement->next_action,
                    'planned_start_date' => $improvement->planned_start_date?->toDateString(),
                    'target_date' => $improvement->target_date?->toDateString(),
                    'planned_start_day' => $improvement->planned_start_day,
                    'target_day' => $improvement->target_day,
                    'tasks' => $improvement->tasks->map(fn ($task) => [
                        'public_id' => $task->public_id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'status' => $task->status,
                        'priority' => $task->priority,
                        'planned_start_date' => $task->planned_start_date?->toDateString(),
                        'due_date' => $task->due_date?->toDateString(),
                        'planned_start_day' => $task->planned_start_day,
                        'due_day' => $task->due_day,
                        'assigned_to' => $task->assigned_to,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    public function listAiRequests(AiAccessKey $key): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);

        return ['requests' => AiRequest::query()
            ->where('workspace_id', $key->workspace_id)
            ->where('status', AiRequest::STATUS_PENDING)
            ->whereHas('project.members', fn ($q) => $q->where('user_id', $key->user_id)->where('status', ProjectMember::STATUS_ACTIVE))
            ->with(['project:id,public_id,name', 'requester:id,name', 'attachments'])
            ->oldest()->get()->map(fn (AiRequest $request) => [
                'public_id' => $request->public_id,
                'project_public_id' => $request->project->public_id,
                'project_name' => $request->project->name,
                'title' => $request->title,
                'instructions' => $request->instructions,
                'requested_by' => $request->requester?->name,
                'created_at' => $request->created_at->toIso8601String(),
                'attachments' => $request->attachments->map(fn (AiRequestAttachment $attachment) => $this->attachmentMetadata($attachment))->all(),
            ])->all()];
    }

    public function claimAiRequest(AiAccessKey $key, string $publicId): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);

        return DB::transaction(function () use ($key, $publicId): array {
            $request = AiRequest::query()->lockForUpdate()
                ->where('workspace_id', $key->workspace_id)->where('public_id', $publicId)
                ->whereHas('project.members', fn ($q) => $q->where('user_id', $key->user_id)->where('status', ProjectMember::STATUS_ACTIVE))
                ->firstOrFail();
            if ($request->status === AiRequest::STATUS_PENDING) {
                $request->update(['status' => AiRequest::STATUS_PROCESSING, 'claimed_by_access_key_id' => $key->id, 'claimed_at' => now()]);
            } elseif ($request->status !== AiRequest::STATUS_PROCESSING || $request->claimed_by_access_key_id !== $key->id) {
                throw ValidationException::withMessages(['request' => 'このAI依頼はすでに処理されています。']);
            }
            $request->load('attachments');

            return [
                'request_public_id' => $request->public_id,
                'status' => $request->status,
                'project_public_id' => $request->project->public_id,
                'instructions' => $request->instructions,
                'attachments' => $request->attachments->map(fn (AiRequestAttachment $attachment) => $this->attachmentMetadata($attachment))->all(),
            ];
        });
    }

    public function getAiRequestAttachment(AiAccessKey $key, string $requestPublicId, string $attachmentPublicId): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);
        $request = AiRequest::query()
            ->where('workspace_id', $key->workspace_id)
            ->where('public_id', $requestPublicId)
            ->whereHas('project.members', fn ($query) => $query
                ->where('user_id', $key->user_id)
                ->where('status', ProjectMember::STATUS_ACTIVE))
            ->firstOrFail();
        if ($request->status !== AiRequest::STATUS_PROCESSING || $request->claimed_by_access_key_id !== $key->id) {
            throw ValidationException::withMessages(['request' => '先にこのAI依頼を引き受けてください。']);
        }
        $attachment = $request->attachments()->where('public_id', $attachmentPublicId)->firstOrFail();
        if (! Storage::disk('local')->exists($attachment->stored_path)) {
            throw ValidationException::withMessages(['attachment' => '添付ファイルの実体が見つかりません。']);
        }
        $bytes = Storage::disk('local')->get($attachment->stored_path);
        $metadata = $this->attachmentMetadata($attachment);
        if (str_starts_with($attachment->mime_type, 'image/')) {
            $content = [['type' => 'image', 'data' => base64_encode($bytes), 'mimeType' => $attachment->mime_type]];
        } elseif ($attachment->extension === 'csv') {
            $encoding = mb_detect_encoding($bytes, ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP'], true);
            $text = $encoding && $encoding !== 'UTF-8' ? mb_convert_encoding($bytes, 'UTF-8', $encoding) : $bytes;
            $content = [['type' => 'text', 'text' => "添付ファイル: {$attachment->original_name}\n\n".$text]];
        } else {
            $content = [[
                'type' => 'resource',
                'resource' => [
                    'uri' => "rise-gate-os://ai-requests/{$request->public_id}/attachments/{$attachment->public_id}",
                    'mimeType' => $attachment->mime_type,
                    'blob' => base64_encode($bytes),
                ],
            ]];
        }

        return ['attachment' => $metadata, '_mcp_content' => $content];
    }

    private function attachmentMetadata(AiRequestAttachment $attachment): array
    {
        return [
            'public_id' => $attachment->public_id,
            'name' => $attachment->original_name,
            'mime_type' => $attachment->mime_type,
            'extension' => $attachment->extension,
            'size_bytes' => $attachment->size_bytes,
            'sha256' => $attachment->sha256,
        ];
    }

    public function submitProposal(AiAccessKey $key, array $arguments): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROPOSALS_CREATE);
        $project = $this->visibleProjects($key)
            ->where('public_id', $arguments['project_public_id'] ?? '')
            ->whereHas('members', fn ($members) => $members
                ->when($key->user_id, fn ($query) => $query->where('user_id', $key->user_id))
                ->whereIn('permission_level', [ProjectMember::PERMISSION_ADMIN, ProjectMember::PERMISSION_EDIT, ProjectMember::PERMISSION_COMMENT]))
            ->firstOrFail();

        $existing = AiProposal::query()
            ->where('workspace_id', $key->workspace_id)
            ->where('source', 'codex')
            ->where('idempotency_key', $arguments['idempotency_key'])
            ->first();
        if ($existing) {
            return $this->proposalResult($existing, true);
        }

        $proposal = DB::transaction(function () use ($key, $project, $arguments): AiProposal {
            $proposal = AiProposal::create([
                'organization_id' => $project->organization_id,
                'workspace_id' => $key->workspace_id,
                'project_id' => $project->id,
                'source' => 'codex',
                'idempotency_key' => $arguments['idempotency_key'],
                'title' => $arguments['title'],
                'summary' => $arguments['summary'] ?? null,
                'evidence' => $arguments['evidence'] ?? null,
                'status' => AiProposal::STATUS_PENDING,
                'requested_by' => $key->user_id,
            ]);
            foreach ($arguments['items'] as $index => $item) {
                $proposal->items()->create([
                    'operation' => $item['operation'],
                    'entity_type' => $item['entity_type'],
                    'target_public_id' => $item['target_public_id'] ?? null,
                    'reference_key' => $item['reference_key'] ?? null,
                    'parent_reference' => $item['parent_reference'] ?? null,
                    'attributes' => $item['attributes'],
                    'sort_order' => ($index + 1) * 10,
                ]);
            }
            if (! empty($arguments['ai_request_public_id'])) {
                $aiRequest = AiRequest::query()->lockForUpdate()
                    ->where('workspace_id', $key->workspace_id)->where('project_id', $project->id)
                    ->where('public_id', $arguments['ai_request_public_id'])->firstOrFail();
                if (! in_array($aiRequest->status, [AiRequest::STATUS_PENDING, AiRequest::STATUS_PROCESSING], true)
                    || ($aiRequest->claimed_by_access_key_id && $aiRequest->claimed_by_access_key_id !== $key->id)) {
                    throw ValidationException::withMessages(['request' => 'このAI依頼には提案を紐づけられません。']);
                }
                $aiRequest->update(['status' => AiRequest::STATUS_PROPOSED, 'claimed_by_access_key_id' => $key->id, 'claimed_at' => $aiRequest->claimed_at ?? now(), 'ai_proposal_id' => $proposal->id]);
            }

            return $proposal;
        });

        return $this->proposalResult($this->validator->validate($proposal), false);
    }

    private function proposalResult(AiProposal $proposal, bool $duplicate): array
    {
        return [
            'proposal_id' => $proposal->public_id,
            'status' => $proposal->status,
            'duplicate' => $duplicate,
            'valid_items_count' => $proposal->items()->where('validation_status', AiProposalValidator::STATUS_VALID)->count(),
            'invalid_items_count' => $proposal->items()->where('validation_status', AiProposalValidator::STATUS_INVALID)->count(),
            'review_url' => route('projects.ai-proposals.show', [$proposal->project_id, $proposal]),
        ];
    }

    private function visibleProjects(AiAccessKey $key)
    {
        return Project::query()
            ->where('owning_workspace_id', $key->workspace_id)
            ->when($key->user_id, fn ($query) => $query->whereHas('members', fn ($members) => $members
                ->where('user_id', $key->user_id)
                ->where('status', ProjectMember::STATUS_ACTIVE)));
    }

    private function requireScope(AiAccessKey $key, string $scope): void
    {
        if (! $key->allows($scope)) {
            throw ValidationException::withMessages(['scope' => 'このAI接続には必要な権限がありません。']);
        }
    }
}
