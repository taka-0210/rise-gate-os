<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Project;
use App\Services\AiProposalValidator;
use App\Support\AiTextIntegrity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AiProposalController extends Controller
{
    public function store(Request $request, AiProposalValidator $proposalValidator): JsonResponse
    {
        /** @var AiAccessKey $accessKey */
        $accessKey = $request->attributes->get('aiAccessKey');
        $validated = $request->validate([
            'project_public_id' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:255'],
            'summary' => ['nullable', 'string'],
            'evidence' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.operation' => ['required', Rule::in([AiProposalItem::OPERATION_CREATE, AiProposalItem::OPERATION_UPDATE, AiProposalItem::OPERATION_DELETE])],
            'items.*.entity_type' => ['required', Rule::in(['project', 'roadmap', 'improvement', 'task'])],
            'items.*.target_public_id' => ['nullable', 'string', 'required_if:items.*.operation,update'],
            'items.*.reference_key' => ['nullable', 'string', 'max:120'],
            'items.*.parent_reference' => ['nullable', 'string', 'max:120'],
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
                ->where('status', \App\Models\ProjectMember::STATUS_ACTIVE)
                ->whereIn('permission_level', [
                    \App\Models\ProjectMember::PERMISSION_ADMIN,
                    \App\Models\ProjectMember::PERMISSION_EDIT,
                    \App\Models\ProjectMember::PERMISSION_COMMENT,
                ])))
            ->first();

        if (! $project) {
            return response()->json(['message' => '指定したProjectはこのAPIキーのWorkspaceに存在しません。'], 404);
        }

        $existing = AiProposal::query()
            ->where('workspace_id', $accessKey->workspace_id)
            ->where('source', 'codex')
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existing) {
            return response()->json($this->responseData($existing, true));
        }

        $proposal = DB::transaction(function () use ($validated, $accessKey, $project): AiProposal {
            $proposal = AiProposal::create([
                'organization_id' => $project->organization_id,
                'workspace_id' => $accessKey->workspace_id,
                'project_id' => $project->id,
                'source' => 'codex',
                'idempotency_key' => $validated['idempotency_key'],
                'title' => $validated['title'],
                'summary' => $validated['summary'] ?? null,
                'evidence' => $validated['evidence'] ?? null,
                'status' => AiProposal::STATUS_PENDING,
                'requested_by' => $accessKey->user_id,
            ]);

            foreach ($validated['items'] as $index => $item) {
                $proposal->items()->create([
                    'operation' => $item['operation'],
                    'entity_type' => $item['entity_type'],
                    'target_public_id' => $item['target_public_id'] ?? null,
                    'reference_key' => $item['reference_key'] ?? null,
                    'parent_reference' => $item['parent_reference'] ?? null,
                    'attributes' => $item['attributes'],
                    'sort_order' => ($index + 1) * 10,
                    'validation_status' => 'pending',
                ]);
            }

            return $proposal->load('items');
        });

        $proposal = $proposalValidator->validate($proposal);

        return response()->json($this->responseData($proposal, false), 201);
    }

    private function responseData(AiProposal $proposal, bool $duplicate): array
    {
        return [
            'proposal_id' => $proposal->public_id,
            'status' => $proposal->status,
            'duplicate' => $duplicate,
            'items_count' => $proposal->items()->count(),
            'valid_items_count' => $proposal->items()->where('validation_status', AiProposalValidator::STATUS_VALID)->count(),
            'invalid_items_count' => $proposal->items()->where('validation_status', AiProposalValidator::STATUS_INVALID)->count(),
            'review_url' => route('projects.ai-proposals.show', [$proposal->project_id, $proposal]),
        ];
    }
}
