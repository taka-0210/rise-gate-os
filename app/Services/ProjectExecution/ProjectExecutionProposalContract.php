<?php

namespace App\Services\ProjectExecution;

use App\Models\AiProposalItem;
use Illuminate\Database\Eloquent\Model;

final class ProjectExecutionProposalContract
{
    public const VERSION = 'project-action.v1';
    public const CAPABILITY = 'project.action.change';
    public const RISK_LEVEL = 'L2';
    public const APPROVAL_POLICY = 'project-execution-role-explicit-human';
    public const ALLOWED_ATTRIBUTES = [
        'project' => ['purpose', 'expected_outcome', 'current_state'],
        'roadmap' => ['title', 'purpose'],
        'improvement' => ['title', 'theme_description'],
        'task' => ['title', 'description', 'done_condition', 'assigned_to', 'reviewer_user_id', 'due_date'],
    ];

    public static function supports(AiProposalItem $item): bool
    {
        return in_array($item->operation, [AiProposalItem::OPERATION_CREATE, AiProposalItem::OPERATION_UPDATE], true)
            && isset(self::ALLOWED_ATTRIBUTES[$item->entity_type])
            && ! ($item->entity_type === 'project' && $item->operation !== AiProposalItem::OPERATION_UPDATE);
    }

    public static function snapshot(Model $model, string $entityType): array
    {
        return collect(self::ALLOWED_ATTRIBUTES[$entityType] ?? [])
            ->mapWithKeys(fn (string $attribute) => [$attribute => $model->getAttribute($attribute)])
            ->all();
    }
}
