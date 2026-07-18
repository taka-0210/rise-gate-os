<?php

namespace App\Services;

use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Project;
use App\Models\ProjectMember;
use Illuminate\Support\Facades\DB;
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
            'status' => $project->status,
            'priority' => $project->priority,
            'roadmaps' => $project->roadmaps->map(fn ($roadmap) => [
                'public_id' => $roadmap->public_id,
                'title' => $roadmap->title,
                'purpose' => $roadmap->purpose,
                'status' => $roadmap->status,
                'improvements' => $roadmap->improvements->map(fn ($improvement) => [
                    'public_id' => $improvement->public_id,
                    'title' => $improvement->title,
                    'status' => $improvement->status,
                    'next_action' => $improvement->next_action,
                    'tasks' => $improvement->tasks->map(fn ($task) => [
                        'public_id' => $task->public_id,
                        'title' => $task->title,
                        'description' => $task->description,
                        'status' => $task->status,
                        'priority' => $task->priority,
                        'due_date' => $task->due_date?->toDateString(),
                        'assigned_to' => $task->assigned_to,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
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
