<?php

namespace App\Services;

use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\AiRequest;
use App\Models\AiRequestAttachment;
use App\Models\Project;
use App\Models\ProjectHandoff;
use App\Models\ProjectMember;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AiMcpToolService
{
    public function __construct(
        private readonly AiProposalValidator $validator,
        private readonly AiProposalFactory $proposalFactory,
        private readonly AiProjectContextGuard $contextGuard,
    ) {}

    public function listProjects(AiAccessKey $key): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);
        $allowedCategories = $this->contextGuard->assertWorkspaceCategories($key);

        $query = $this->visibleProjects($key);
        $countRelations = array_values(array_filter([
            in_array('roadmaps', $allowedCategories, true) ? 'roadmaps' : null,
            in_array('improvements', $allowedCategories, true) ? 'improvements' : null,
            in_array('tasks', $allowedCategories, true) ? 'tasks' : null,
        ]));
        if ($countRelations !== []) {
            $query->withCount($countRelations);
        }
        $projects = $query->orderBy('name')->get();

        return ['projects' => $projects->map(fn (Project $project) => [
            'public_id' => $project->public_id,
            'name' => $project->name,
            'summary' => $project->summary,
            'plan_version' => $project->plan_version,
            'roadmaps_count' => in_array('roadmaps', $allowedCategories, true) ? $project->roadmaps_count : null,
            'improvements_count' => in_array('improvements', $allowedCategories, true) ? $project->improvements_count : null,
            'tasks_count' => in_array('tasks', $allowedCategories, true) ? $project->tasks_count : null,
        ])->all()];
    }

    public function getProjectPlan(AiAccessKey $key, string $publicId): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);
        $this->contextGuard->assertKeyIdentity($key);
        $project = $this->visibleProjects($key)
            ->where('public_id', $publicId)
            ->firstOrFail();
        $allowedCategories = $this->contextGuard->assertProposalContext($key, $project);
        $load = [];
        if (in_array('roadmaps', $allowedCategories, true)) {
            $load[] = 'roadmaps';
        }
        if (in_array('roadmaps', $allowedCategories, true) && in_array('improvements', $allowedCategories, true)) {
            $load[] = 'roadmaps.improvements';
        }
        if (in_array('roadmaps', $allowedCategories, true)
            && in_array('improvements', $allowedCategories, true)
            && in_array('tasks', $allowedCategories, true)) {
            $load[] = 'roadmaps.improvements.tasks';
        }
        if ($load !== []) {
            $project->load($load);
        }
        $latestHandoff = $project->handoffs()
            ->where('status', ProjectHandoff::STATUS_APPROVED)
            ->latest('reviewed_at')
            ->first();
        $entityCount = $project->relationLoaded('roadmaps')
            ? $project->roadmaps->sum(fn ($roadmap): int => 1
                + ($roadmap->relationLoaded('improvements') ? $roadmap->improvements->sum(fn ($improvement): int => 1
                    + ($improvement->relationLoaded('tasks') ? $improvement->tasks->count() : 0)) : 0))
            : 0;
        if ($entityCount > (int) config('services.ai.scope_one_context_max_entities', 500)) {
            throw ValidationException::withMessages(['ai_context' => 'Project計画がAI Contextの件数上限を超えています。対象を分けて確認してください。']);
        }

        $context = [
            'public_id' => $project->public_id,
            'plan_version' => $project->plan_version,
            'proposal_contract_version' => AiProposalContract::VERSION,
            'name' => $project->name,
            'summary' => $project->summary,
            'current_state' => $project->current_state,
            'desired_future_state' => $project->desired_future_state,
            'handoff' => [
                'completed_work' => $latestHandoff?->completed_work,
                'next_work' => $latestHandoff?->next_work,
                'approved_at' => $latestHandoff?->reviewed_at?->toIso8601String(),
                'pending_proposals_count' => $project->handoffs()
                    ->where('status', ProjectHandoff::STATUS_PENDING)
                    ->count(),
            ],
            'roadmaps' => ! in_array('roadmaps', $allowedCategories, true) ? [] : $project->roadmaps->map(fn ($roadmap) => [
                'public_id' => $roadmap->public_id,
                'plan_version' => $roadmap->plan_version,
                'title' => $roadmap->title,
                'purpose' => $roadmap->purpose,
                'improvements' => ! in_array('improvements', $allowedCategories, true) ? [] : $roadmap->improvements->map(fn ($improvement) => [
                    'public_id' => $improvement->public_id,
                    'plan_version' => $improvement->plan_version,
                    'title' => $improvement->title,
                    'current_state' => $improvement->current_state,
                    'desired_state' => $improvement->desired_state,
                    'problem' => $improvement->problem,
                    'hypothesis' => $improvement->hypothesis,
                    'action' => $improvement->action,
                    'next_action' => $improvement->next_action,
                    'tasks' => ! in_array('tasks', $allowedCategories, true) ? [] : $improvement->tasks->map(fn ($task) => [
                        'public_id' => $task->public_id,
                        'plan_version' => $task->plan_version,
                        'title' => $task->title,
                        'description' => $task->description,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ];
        if (strlen(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > (int) config('services.ai.scope_one_context_max_chars', 100000)) {
            throw ValidationException::withMessages(['ai_context' => 'Project計画がAI Contextの文字数上限を超えています。対象を分けて確認してください。']);
        }

        return $context;
    }

    public function listAiRequests(AiAccessKey $key): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROJECTS_READ);
        $this->contextGuard->assertWorkspaceCategories($key, AiProjectContextGuard::SCOPE_ONE_CATEGORIES);
        $projectIds = $this->visibleProjects($key)->pluck('id');

        return ['requests' => AiRequest::query()
            ->where('workspace_id', $key->workspace_id)
            ->whereIn('project_id', $projectIds)
            ->where('status', AiRequest::STATUS_PENDING)
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
        $this->contextGuard->assertWorkspaceCategories($key, AiProjectContextGuard::SCOPE_ONE_CATEGORIES);

        return DB::transaction(function () use ($key, $publicId): array {
            $request = AiRequest::query()->with('project')->lockForUpdate()
                ->where('workspace_id', $key->workspace_id)->where('public_id', $publicId)
                ->whereHas('project.members', fn ($q) => $q->where('user_id', $key->user_id)->where('status', ProjectMember::STATUS_ACTIVE))
                ->firstOrFail();
            $this->contextGuard->assertProposalContext($key, $request->project);
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
        $this->contextGuard->assertWorkspaceCategories($key, AiProjectContextGuard::SCOPE_ONE_CATEGORIES);
        $request = AiRequest::query()->with('project')
            ->where('workspace_id', $key->workspace_id)
            ->where('public_id', $requestPublicId)
            ->whereHas('project.members', fn ($query) => $query
                ->where('user_id', $key->user_id)
                ->where('status', ProjectMember::STATUS_ACTIVE))
            ->firstOrFail();
        $this->contextGuard->assertProposalContext($key, $request->project);
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
        $this->contextGuard->assertKeyIdentity($key);
        $project = $this->visibleProjects($key)
            ->where('public_id', $arguments['project_public_id'] ?? '')
            ->whereHas('members', fn ($members) => $members
                ->when($key->user_id, fn ($query) => $query->where('user_id', $key->user_id))
                ->whereIn('permission_level', [ProjectMember::PERMISSION_ADMIN, ProjectMember::PERMISSION_EDIT, ProjectMember::PERMISSION_COMMENT]))
            ->firstOrFail();
        $this->contextGuard->assertProposalContext($key, $project, $arguments['items'] ?? []);

        $existing = AiProposal::query()
            ->where('workspace_id', $key->workspace_id)
            ->where('source', 'codex')
            ->where('idempotency_key', $arguments['idempotency_key'])
            ->first();
        if ($existing) {
            if ($existing->project_id !== $project->id || $existing->requested_by !== $key->user_id) {
                throw ValidationException::withMessages(['idempotency_key' => 'Idempotency Keyは別の提案ですでに使用されています。']);
            }
            if (! $this->proposalFactory->matchesInput($existing, $arguments)) {
                throw ValidationException::withMessages(['idempotency_key' => '同じIdempotency Keyを異なる提案内容には使用できません。']);
            }

            return $this->proposalResult($existing, true);
        }

        try {
            $proposal = DB::transaction(function () use ($key, $project, $arguments): AiProposal {
                $proposal = $this->proposalFactory->create($key, $project, $arguments);
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
        } catch (UniqueConstraintViolationException $error) {
            $proposal = AiProposal::query()
                ->where('workspace_id', $key->workspace_id)
                ->where('source', 'codex')
                ->where('idempotency_key', $arguments['idempotency_key'])
                ->first();
            if (! $proposal) {
                throw $error;
            }
            if ($proposal->project_id !== $project->id || $proposal->requested_by !== $key->user_id) {
                throw ValidationException::withMessages(['idempotency_key' => 'Idempotency Keyは別の提案ですでに使用されています。']);
            }
            if (! $this->proposalFactory->matchesInput($proposal, $arguments)) {
                throw ValidationException::withMessages(['idempotency_key' => '同じIdempotency Keyを異なる提案内容には使用できません。']);
            }

            return $this->proposalResult($proposal, true);
        }

        return $this->proposalResult($this->validator->validate($proposal), false);
    }

    public function submitHandoffProposal(AiAccessKey $key, array $arguments): array
    {
        $this->requireScope($key, AiAccessKey::SCOPE_PROPOSALS_CREATE);
        $this->contextGuard->assertKeyIdentity($key);
        $project = $this->visibleProjects($key)
            ->where('public_id', $arguments['project_public_id'])
            ->whereHas('members', fn ($members) => $members
                ->when($key->user_id, fn ($query) => $query->where('user_id', $key->user_id))
                ->whereIn('permission_level', [
                    ProjectMember::PERMISSION_ADMIN,
                    ProjectMember::PERMISSION_EDIT,
                    ProjectMember::PERMISSION_COMMENT,
                ]))
            ->firstOrFail();
        $this->contextGuard->assertProposalContext($key, $project);

        $existing = $project->handoffs()
            ->where('idempotency_key', $arguments['idempotency_key'])
            ->first();
        if ($existing) {
            return $this->handoffResult($project, $existing, true);
        }

        $handoff = $project->handoffs()->create([
            'source' => ProjectHandoff::SOURCE_CODEX,
            'status' => ProjectHandoff::STATUS_PENDING,
            'completed_work' => $arguments['completed_work'],
            'next_work' => $arguments['next_work'],
            'idempotency_key' => $arguments['idempotency_key'],
            'proposed_by' => $key->user_id,
        ]);

        return $this->handoffResult($project, $handoff, false);
    }

    private function handoffResult(Project $project, ProjectHandoff $handoff, bool $duplicate): array
    {
        return [
            'handoff_proposal_id' => $handoff->public_id,
            'status' => $handoff->status,
            'duplicate' => $duplicate,
            'review_url' => route('projects.handoffs.index', $project),
        ];
    }

    private function proposalResult(AiProposal $proposal, bool $duplicate): array
    {
        return [
            'proposal_id' => $proposal->public_id,
            'mode' => $proposal->mode,
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
            ->where('organization_id', $key->workspace->organization_id)
            ->when($key->user_id, fn ($query) => $query->whereHas('members', fn ($members) => $members
                ->where('user_id', $key->user_id)
                ->where('status', ProjectMember::STATUS_ACTIVE)
                ->where('project_role', '!=', ProjectMember::ROLE_CLIENT)));
    }

    private function requireScope(AiAccessKey $key, string $scope): void
    {
        if (! $key->allows($scope)) {
            throw ValidationException::withMessages(['scope' => 'このAI接続には必要な権限がありません。']);
        }
    }
}
