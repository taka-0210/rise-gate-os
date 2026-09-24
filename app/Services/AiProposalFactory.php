<?php

namespace App\Services;

use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Services\ProjectExecution\ProjectExecutionProposalContract;

class AiProposalFactory
{
    public function create(AiAccessKey $key, Project $project, array $input): AiProposal
    {
        return DB::transaction(function () use ($key, $project, $input): AiProposal {
            $scopeEight = ($input['contract_version'] ?? null) === ProjectExecutionProposalContract::VERSION;
            $referenceKeys = collect($input['items'])->pluck('reference_key')->filter()->all();
            $proposal = AiProposal::create([
                'organization_id' => $project->organization_id,
                'workspace_id' => $key->workspace_id,
                'project_id' => $project->id,
                'source' => 'codex',
                'mode' => AiProposal::MODE_DIFFERENTIAL,
                'contract_version' => $input['contract_version'],
                'capability' => $scopeEight ? ProjectExecutionProposalContract::CAPABILITY : AiProposalContract::CAPABILITY,
                'risk_level' => $scopeEight ? ProjectExecutionProposalContract::RISK_LEVEL : AiProposalContract::RISK_LEVEL,
                'expected_project_version' => $input['expected_project_version'],
                'approval_policy' => $scopeEight ? ProjectExecutionProposalContract::APPROVAL_POLICY : AiProposalContract::APPROVAL_POLICY,
                'idempotency_key' => $input['idempotency_key'],
                'title' => $input['title'],
                'summary' => $input['summary'] ?? null,
                'evidence' => $input['evidence'] ?? null,
                'status' => AiProposal::STATUS_PENDING,
                'requested_by' => $key->user_id,
            ]);

            foreach ($input['items'] as $index => $data) {
                $target = $this->target($project, $data['entity_type'], $data['target_public_id'] ?? null);
                $before = $target ? AiProposalContract::snapshot($target, $data['entity_type'], $input['contract_version']) : null;
                $after = $data['attributes'];
                $parentReference = $data['parent_reference'] ?? null;
                $proposal->items()->create([
                    'operation' => $data['operation'],
                    'entity_type' => $data['entity_type'],
                    'target_public_id' => $data['target_public_id'] ?? null,
                    'reference_key' => $data['reference_key'] ?? null,
                    'parent_reference' => $parentReference,
                    'depends_on' => $data['depends_on'] ?? (in_array($parentReference, $referenceKeys, true) ? [$parentReference] : []),
                    'attributes' => $after,
                    'before' => $before,
                    'after' => $after,
                    'expected_version' => $data['expected_version'],
                    'sort_order' => ($index + 1) * 10,
                    'validation_status' => 'pending',
                ]);
            }

            $proposal->load('items');
            $proposal->update(['content_hash' => AiProposalContract::proposalHash($proposal)]);

            return $proposal;
        });
    }

    public function matchesInput(AiProposal $proposal, array $input): bool
    {
        $proposal->loadMissing('items');
        $referenceKeys = collect($input['items'])->pluck('reference_key')->filter()->all();

        $stored = [
            'contract_version' => $proposal->contract_version,
            'expected_project_version' => $proposal->expected_project_version,
            'mode' => $proposal->mode,
            'title' => $proposal->title,
            'summary' => $proposal->summary,
            'evidence' => $proposal->evidence,
            'items' => $proposal->items->map(fn ($item): array => [
                'operation' => $item->operation,
                'entity_type' => $item->entity_type,
                'target_public_id' => $item->target_public_id,
                'reference_key' => $item->reference_key,
                'parent_reference' => $item->parent_reference,
                'depends_on' => $item->depends_on ?? [],
                'expected_version' => $item->expected_version,
                'attributes' => $item->attributes,
            ])->values()->all(),
        ];
        $submitted = [
            'contract_version' => $input['contract_version'],
            'expected_project_version' => $input['expected_project_version'],
            'mode' => $input['mode'] ?? AiProposal::MODE_DIFFERENTIAL,
            'title' => $input['title'],
            'summary' => $input['summary'] ?? null,
            'evidence' => $input['evidence'] ?? null,
            'items' => collect($input['items'])->map(function (array $item) use ($referenceKeys): array {
                $parentReference = $item['parent_reference'] ?? null;

                return [
                    'operation' => $item['operation'],
                    'entity_type' => $item['entity_type'],
                    'target_public_id' => $item['target_public_id'] ?? null,
                    'reference_key' => $item['reference_key'] ?? null,
                    'parent_reference' => $parentReference,
                    'depends_on' => $item['depends_on'] ?? (in_array($parentReference, $referenceKeys, true) ? [$parentReference] : []),
                    'expected_version' => $item['expected_version'],
                    'attributes' => $item['attributes'],
                ];
            })->values()->all(),
        ];

        return hash_equals($this->fingerprint($stored), $this->fingerprint($submitted));
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->canonicalize(...), $value);
        }

        ksort($value);

        return array_map($this->canonicalize(...), $value);
    }

    private function target(Project $project, string $type, ?string $publicId): ?Model
    {
        if (! $publicId) {
            return null;
        }

        return match ($type) {
            'project' => hash_equals($project->public_id, $publicId) ? $project : null,
            'roadmap' => $project->roadmaps()->where('public_id', $publicId)->first(),
            'improvement' => $project->improvements()->where('public_id', $publicId)->first(),
            'task' => $project->tasks()->where('public_id', $publicId)->first(),
            default => null,
        };
    }
}
