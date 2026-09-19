<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use Illuminate\Database\Eloquent\Model;

class AiProposalContract
{
    public const VERSION = 'project-plan.v1';

    public const CAPABILITY = 'project.plan.change';

    public const RISK_LEVEL = 'L2';

    public const APPROVAL_POLICY = 'project-role-explicit-human';

    public const ALLOWED_ATTRIBUTES = [
        'project' => ['summary', 'current_state', 'desired_future_state'],
        'roadmap' => ['title', 'purpose'],
        'improvement' => ['title', 'current_state', 'desired_state', 'problem', 'hypothesis', 'action', 'next_action'],
        'task' => ['title', 'description'],
    ];

    public const DATA_CATEGORY_BY_ENTITY = [
        'project' => 'project_metadata',
        'roadmap' => 'roadmaps',
        'improvement' => 'improvements',
        'task' => 'tasks',
    ];

    public static function supports(AiProposalItem $item): bool
    {
        return in_array($item->operation, [AiProposalItem::OPERATION_CREATE, AiProposalItem::OPERATION_UPDATE], true)
            && isset(self::ALLOWED_ATTRIBUTES[$item->entity_type])
            && ! ($item->entity_type === 'project' && $item->operation !== AiProposalItem::OPERATION_UPDATE);
    }

    public static function proposalHash(AiProposal $proposal): string
    {
        $proposal->loadMissing('items');
        $payload = [
            'contract_version' => $proposal->contract_version,
            'capability' => $proposal->capability,
            'risk_level' => $proposal->risk_level,
            'approval_policy' => $proposal->approval_policy,
            'organization_id' => $proposal->organization_id,
            'workspace_id' => $proposal->workspace_id,
            'project_id' => $proposal->project_id,
            'requested_by' => $proposal->requested_by,
            'mode' => $proposal->mode,
            'title' => $proposal->title,
            'summary' => $proposal->summary,
            'evidence' => $proposal->evidence,
            'expected_project_version' => $proposal->expected_project_version,
            'items' => $proposal->items->map(fn (AiProposalItem $item) => [
                'public_id' => $item->public_id, 'operation' => $item->operation,
                'entity_type' => $item->entity_type, 'target_public_id' => $item->target_public_id,
                'reference_key' => $item->reference_key, 'parent_reference' => $item->parent_reference,
                'depends_on' => $item->depends_on, 'before' => $item->before, 'after' => $item->after,
                'expected_version' => $item->expected_version, 'sort_order' => $item->sort_order,
            ])->values()->all(),
        ];

        return hash('sha256', json_encode(self::canonicalize($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function parentType(string $entityType): ?string
    {
        return match ($entityType) {
            'improvement' => 'roadmap',
            'task' => 'improvement',
            default => null,
        };
    }

    public static function snapshot(Model $model, string $entityType): array
    {
        $result = [];
        foreach (self::ALLOWED_ATTRIBUTES[$entityType] ?? [] as $attribute) {
            $result[$attribute] = $model->getAttribute($attribute);
        }

        return $result;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value);

        return array_map(self::canonicalize(...), $value);
    }
}
