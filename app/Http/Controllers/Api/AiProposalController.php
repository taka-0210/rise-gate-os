<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAccessKey;
use App\Models\AiAuditLog;
use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Services\AiProjectContextGuard;
use App\Services\AiProposalContract;
use App\Services\ProjectExecution\ProjectExecutionProposalContract;
use App\Services\AiProposalFactory;
use App\Services\AiProposalValidator;
use App\Support\AiTextIntegrity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiProposalController extends Controller
{
    public function store(Request $request, AiProposalValidator $proposalValidator, AiProposalFactory $factory, AiProjectContextGuard $contextGuard): JsonResponse
    {
        /** @var AiAccessKey $accessKey */
        $accessKey = $request->attributes->get('aiAccessKey');
        $validated = $request->validate([
            'project_public_id' => ['required', 'string'],
            'contract_version' => ['required', Rule::in([AiProposalContract::VERSION, ProjectExecutionProposalContract::VERSION])],
            'expected_project_version' => ['required', 'integer', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:255'],
            'mode' => ['sometimes', Rule::in([AiProposal::MODE_DIFFERENTIAL])],
            'summary' => ['nullable', 'string'],
            'evidence' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.operation' => ['required', Rule::in([AiProposalItem::OPERATION_CREATE, AiProposalItem::OPERATION_UPDATE])],
            'items.*.entity_type' => ['required', Rule::in(['project', 'roadmap', 'improvement', 'task'])],
            'items.*.target_public_id' => ['nullable', 'string', 'required_if:items.*.operation,update'],
            'items.*.reference_key' => ['nullable', 'string', 'max:120'],
            'items.*.parent_reference' => ['nullable', 'string', 'max:120'],
            'items.*.depends_on' => ['sometimes', 'array'],
            'items.*.depends_on.*' => ['string', 'max:120', 'distinct'],
            'items.*.expected_version' => ['required', 'integer', 'min:1'],
            'items.*.attributes' => ['present', 'array'],
        ]);

        if (AiTextIntegrity::containsMojibake([
            $validated['title'],
            $validated['summary'] ?? null,
            array_column($validated['items'], 'attributes'),
        ])) {
            throw ValidationException::withMessages(['proposal' => AiTextIntegrity::ERROR_MESSAGE]);
        }

        $project = Project::query()
            ->where('public_id', $validated['project_public_id'])
            ->where('owning_workspace_id', $accessKey->workspace_id)
            ->when($accessKey->user_id, fn ($query) => $query->whereHas('members', fn ($members) => $members
                ->where('user_id', $accessKey->user_id)
                ->where('status', ProjectMember::STATUS_ACTIVE)
                ->whereIn('permission_level', [
                    ProjectMember::PERMISSION_ADMIN,
                    ProjectMember::PERMISSION_EDIT,
                    ProjectMember::PERMISSION_COMMENT,
                ])))
            ->first();

        if (! $project) {
            return response()->json(['message' => '指定したProjectはこのAPIキーのWorkspaceに存在しません。'], 404);
        }
        $contextGuard->assertProposalContext($accessKey, $project, $validated['items']);

        $existing = AiProposal::query()
            ->where('workspace_id', $accessKey->workspace_id)
            ->where('source', 'codex')
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing) {
            abort_unless($existing->project_id === $project->id && $existing->requested_by === $accessKey->user_id, 409, 'Idempotency Keyは別の提案ですでに使用されています。');
            abort_unless($factory->matchesInput($existing, $validated), 409, '同じIdempotency Keyを異なる提案内容には使用できません。');
            $this->audit($accessKey, $project, $existing, true);

            return response()->json($this->responseData($existing, true));
        }

        try {
            $proposal = $factory->create($accessKey, $project, $validated);
        } catch (UniqueConstraintViolationException $error) {
            $proposal = AiProposal::query()
                ->where('workspace_id', $accessKey->workspace_id)
                ->where('source', 'codex')
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if (! $proposal) {
                throw $error;
            }
            abort_unless($proposal->project_id === $project->id && $proposal->requested_by === $accessKey->user_id, 409, 'Idempotency Keyは別の提案ですでに使用されています。');
            abort_unless($factory->matchesInput($proposal, $validated), 409, '同じIdempotency Keyを異なる提案内容には使用できません。');
            $this->audit($accessKey, $project, $proposal, true);

            return response()->json($this->responseData($proposal, true));
        }

        $proposal = $proposalValidator->validate($proposal);
        $this->audit($accessKey, $project, $proposal, false);

        return response()->json($this->responseData($proposal, false), 201);
    }

    private function responseData(AiProposal $proposal, bool $duplicate): array
    {
        return [
            'proposal_id' => $proposal->public_id,
            'mode' => $proposal->mode,
            'status' => $proposal->status,
            'duplicate' => $duplicate,
            'items_count' => $proposal->items()->count(),
            'valid_items_count' => $proposal->items()->where('validation_status', AiProposalValidator::STATUS_VALID)->count(),
            'invalid_items_count' => $proposal->items()->where('validation_status', AiProposalValidator::STATUS_INVALID)->count(),
            'review_url' => route('projects.ai-proposals.show', [$proposal->project_id, $proposal]),
        ];
    }

    private function audit(AiAccessKey $key, Project $project, AiProposal $proposal, bool $duplicate): void
    {
        AiAuditLog::create([
            'workspace_id' => $key->workspace_id,
            'user_id' => $key->user_id,
            'ai_access_key_id' => $key->id,
            'project_id' => $project->id,
            'ai_proposal_id' => $proposal->id,
            'event' => 'api.proposal.submitted',
            'succeeded' => true,
            'metadata' => [
                'duplicate' => $duplicate,
                'resource_ids' => ['project' => $project->public_id, 'proposal' => $proposal->public_id],
            ],
            'occurred_at' => now(),
        ]);
    }
}
