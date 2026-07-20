<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiProposalValidator
{
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';

    private const ALLOWED_ATTRIBUTES = [
        'roadmap' => ['title', 'purpose', 'status', 'sort_order', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'],
        'improvement' => ['title', 'roadmap_public_id', 'current_state', 'desired_state', 'problem', 'hypothesis', 'action', 'result', 'impact', 'next_action', 'status', 'visibility', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'],
        'task' => ['title', 'improvement_public_id', 'description', 'status', 'priority', 'planned_start_date', 'due_date', 'planned_start_day', 'due_day'],
    ];

    public function validate(AiProposal $proposal): AiProposal
    {
        $proposal->loadMissing(['project', 'items']);

        foreach ($proposal->items as $item) {
            $errors = $this->errors($proposal->project, $item);
            $item->update([
                'validation_status' => $errors === [] ? self::STATUS_VALID : self::STATUS_INVALID,
                'validation_message' => $errors === [] ? null : implode("\n", $errors),
            ]);
        }

        return $proposal->fresh('items');
    }

    private function errors(Project $project, AiProposalItem $item): array
    {
        $attributes = $item->attributes ?? [];
        $allowed = self::ALLOWED_ATTRIBUTES[$item->entity_type] ?? [];
        $unknown = array_diff(array_keys($attributes), $allowed);
        $errors = [];

        if ($unknown !== []) {
            $errors[] = '許可されていない項目: '.implode(', ', $unknown);
        }

        if (in_array($item->operation, [AiProposalItem::OPERATION_UPDATE, AiProposalItem::OPERATION_DELETE], true) && ! $this->targetExists($project, $item)) {
            $errors[] = '更新対象がこのProject内に存在しません。';
        }

        if ($item->operation === AiProposalItem::OPERATION_DELETE) {
            if ($attributes !== []) {
                $errors[] = '削除提案に変更属性は指定できません。';
            }

            $childError = $this->deleteChildError($project, $item);
            if ($childError) {
                $errors[] = $childError;
            }

            return array_values(array_unique($errors));
        }

        $validator = Validator::make($attributes, $this->rules($item));
        if ($validator->fails()) {
            $errors = array_merge($errors, $validator->errors()->all());
        }

        $relationError = $this->relationError($project, $item);
        if ($relationError) {
            $errors[] = $relationError;
        }

        return array_values(array_unique($errors));
    }

    private function rules(AiProposalItem $item): array
    {
        $titleRule = $item->operation === AiProposalItem::OPERATION_CREATE ? ['required', 'string', 'max:255'] : ['sometimes', 'string', 'max:255'];

        return match ($item->entity_type) {
            'roadmap' => [
                'title' => $titleRule,
                'purpose' => ['nullable', 'string'],
                'status' => ['sometimes', Rule::in(array_keys(Roadmap::statuses()))],
                'sort_order' => ['sometimes', 'integer', 'min:0'],
                'planned_start_date' => ['nullable', 'date'],
                'target_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
                'planned_start_day' => ['nullable', 'integer', 'min:1', 'lte:target_day'],
                'target_day' => ['nullable', 'integer', 'min:1', 'max:3650'],
            ],
            'improvement' => [
                'title' => $titleRule,
                'roadmap_public_id' => ['nullable', 'string'],
                'current_state' => ['nullable', 'string'],
                'desired_state' => ['nullable', 'string'],
                'problem' => ['nullable', 'string'],
                'hypothesis' => ['nullable', 'string'],
                'action' => ['nullable', 'string'],
                'result' => ['nullable', 'string'],
                'impact' => ['nullable', 'string'],
                'next_action' => ['nullable', 'string'],
                'status' => ['sometimes', Rule::in(array_keys(Improvement::statuses()))],
                'visibility' => ['sometimes', Rule::in(array_keys(Improvement::visibilities()))],
                'planned_start_date' => ['nullable', 'date'],
                'target_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
                'planned_start_day' => ['nullable', 'integer', 'min:1', 'lte:target_day'],
                'target_day' => ['nullable', 'integer', 'min:1', 'max:3650'],
            ],
            'task' => [
                'title' => $titleRule,
                'improvement_public_id' => ['nullable', 'string'],
                'description' => ['nullable', 'string'],
                'status' => ['sometimes', Rule::in(array_keys(Task::statuses()))],
                'priority' => ['sometimes', Rule::in(array_keys(Task::priorities()))],
                'planned_start_date' => ['nullable', 'date'],
                'due_date' => ['nullable', 'date', 'after_or_equal:planned_start_date'],
                'planned_start_day' => ['nullable', 'integer', 'min:1', 'lte:due_day'],
                'due_day' => ['nullable', 'integer', 'min:1', 'max:3650'],
            ],
            default => [],
        };
    }

    private function deleteChildError(Project $project, AiProposalItem $item): ?string
    {
        $deletedTargets = $item->proposal->items()
            ->where('operation', AiProposalItem::OPERATION_DELETE)
            ->pluck('target_public_id')
            ->filter();

        if ($item->entity_type === 'roadmap') {
            $remaining = $project->improvements()
                ->whereHas('roadmap', fn ($query) => $query->where('public_id', $item->target_public_id))
                ->whereNotIn('public_id', $deletedTargets)
                ->exists();

            return $remaining ? '取り組みが残っているRoadmapは削除できません。子要素も同じ提案で削除してください。' : null;
        }

        if ($item->entity_type === 'improvement') {
            $remaining = $project->tasks()
                ->whereHas('improvement', fn ($query) => $query->where('public_id', $item->target_public_id))
                ->whereNotIn('public_id', $deletedTargets)
                ->exists();

            return $remaining ? 'Taskが残っている取り組みは削除できません。子要素も同じ提案で削除してください。' : null;
        }

        return null;
    }

    private function targetExists(Project $project, AiProposalItem $item): bool
    {
        if (! $item->target_public_id) {
            return false;
        }

        return match ($item->entity_type) {
            'roadmap' => $project->roadmaps()->where('public_id', $item->target_public_id)->exists(),
            'improvement' => $project->improvements()->where('public_id', $item->target_public_id)->exists(),
            'task' => $project->tasks()->where('public_id', $item->target_public_id)->exists(),
            default => false,
        };
    }

    private function relationError(Project $project, AiProposalItem $item): ?string
    {
        $attributes = $item->attributes ?? [];

        if ($item->parent_reference) {
            $expectedParentType = match ($item->entity_type) {
                'improvement' => 'roadmap',
                'task' => 'improvement',
                default => null,
            };
            $parent = $item->proposal->items()
                ->where('reference_key', $item->parent_reference)
                ->where('sort_order', '<', $item->sort_order)
                ->first();

            if (! $expectedParentType || ! $parent || $parent->entity_type !== $expectedParentType || $parent->operation !== AiProposalItem::OPERATION_CREATE) {
                return '提案内の親参照が無効、または親項目より先に配置されていません。';
            }

            return null;
        }

        if ($item->entity_type === 'improvement' && ! empty($attributes['roadmap_public_id'])) {
            return $project->roadmaps()->where('public_id', $attributes['roadmap_public_id'])->exists()
                ? null
                : '指定したRoadmapがこのProject内に存在しません。';
        }

        if ($item->entity_type === 'task' && ! empty($attributes['improvement_public_id'])) {
            return $project->improvements()->where('public_id', $attributes['improvement_public_id'])->exists()
                ? null
                : '指定した取り組みがこのProject内に存在しません。';
        }

        return null;
    }
}
