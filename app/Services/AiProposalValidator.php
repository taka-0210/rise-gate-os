<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\Roadmap;
use App\Models\Task;
use App\Support\AiTextIntegrity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiProposalValidator
{
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';

    private const ALLOWED_ATTRIBUTES = [
        'project' => ['summary', 'current_state', 'desired_future_state'],
        'roadmap' => ['title', 'purpose', 'status', 'sort_order', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'],
        'improvement' => ['title', 'roadmap_public_id', 'current_state', 'desired_state', 'problem', 'hypothesis', 'action', 'result', 'impact', 'next_action', 'planned_effort_days', 'status', 'visibility', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'],
        'task' => ['title', 'improvement_public_id', 'description', 'status', 'priority', 'planned_start_date', 'due_date', 'planned_start_day', 'due_day'],
    ];

    public function validate(AiProposal $proposal): AiProposal
    {
        $proposal->loadMissing(['project', 'items']);
        $proposalErrors = $this->proposalErrors($proposal);

        foreach ($proposal->items as $item) {
            $errors = array_merge($proposalErrors, $this->errors($proposal->project, $item));
            $item->update([
                'validation_status' => $errors === [] ? self::STATUS_VALID : self::STATUS_INVALID,
                'validation_message' => $errors === [] ? null : implode("\n", $errors),
            ]);
        }

        return $proposal->fresh('items');
    }

    private function proposalErrors(AiProposal $proposal): array
    {
        if (! $proposal->replacesTimeline()) {
            return [];
        }

        $errors = [];
        if (! $proposal->items->contains(fn (AiProposalItem $item): bool =>
            $item->entity_type === 'roadmap'
            && $item->operation === AiProposalItem::OPERATION_CREATE
        )) {
            $errors[] = '全面置換には、新しいタイムラインのRoadmapが1件以上必要です。';
        }

        $referenceKeys = $proposal->items->pluck('reference_key')->filter();
        if ($referenceKeys->duplicates()->isNotEmpty()) {
            $errors[] = '全面置換の参照キーは重複できません。';
        }

        return $errors;
    }

    private function errors(Project $project, AiProposalItem $item): array
    {
        $attributes = $item->attributes ?? [];
        $allowed = self::ALLOWED_ATTRIBUTES[$item->entity_type] ?? [];
        $unknown = array_diff(array_keys($attributes), $allowed);
        $errors = [];

        if (AiTextIntegrity::containsMojibake($attributes)) {
            $errors[] = AiTextIntegrity::ERROR_MESSAGE;
        }

        if ($unknown !== []) {
            $errors[] = '許可されていない項目: '.implode(', ', $unknown);
        }

        if ($proposal = $item->proposal) {
            $replacementError = $this->replacementError($proposal, $project, $item);
            if ($replacementError) {
                $errors[] = $replacementError;
            }
        }

        if ($item->entity_type === 'project') {
            if ($item->operation !== AiProposalItem::OPERATION_UPDATE) {
                $errors[] = 'Project基本情報は更新提案だけを受け付けます。';
            }
            if (array_intersect(array_keys($attributes), self::ALLOWED_ATTRIBUTES['project']) === []) {
                $errors[] = '概要・現状・目指す未来のカタチのいずれかを指定してください。';
            }
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
            'project' => [
                'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'current_state' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'desired_future_state' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ],
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
                'planned_effort_days' => ['sometimes', 'nullable', 'numeric', 'min:0.25', 'max:999.99'],
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

    private function replacementError(AiProposal $proposal, Project $project, AiProposalItem $item): ?string
    {
        if (! $proposal->replacesTimeline()) {
            return null;
        }

        if ($item->entity_type === 'project') {
            return $item->operation === AiProposalItem::OPERATION_UPDATE
                ? null
                : '全面置換ではProject基本情報は更新提案だけを指定できます。';
        }

        if ($item->operation !== AiProposalItem::OPERATION_CREATE) {
            return '全面置換では既存項目の更新・削除を列挙せず、新しいタイムラインを追加項目だけで指定してください。';
        }

        if ($item->entity_type === 'improvement' && ! $item->parent_reference) {
            return '全面置換の取り組みには、新規Roadmapの親参照が必要です。';
        }
        if ($item->entity_type === 'task' && ! $item->parent_reference) {
            return '全面置換のTaskには、新規取り組みの親参照が必要です。';
        }

        $attributes = $item->attributes ?? [];
        $start = $attributes['planned_start_day'] ?? null;
        $end = $attributes[$item->entity_type === 'task' ? 'due_day' : 'target_day'] ?? null;
        if ($project->duration_days && $start && $end
            && ($start < 1 || $end > $project->duration_days)) {
            return "期間はProjectの1日目〜{$project->duration_days}日目の範囲内で設定してください。";
        }

        if (! $item->parent_reference || ! $start || ! $end) {
            return null;
        }

        $parent = $proposal->items()
            ->where('reference_key', $item->parent_reference)
            ->where('sort_order', '<', $item->sort_order)
            ->first();
        if (! $parent) {
            return null;
        }

        $parentAttributes = $parent->attributes ?? [];
        $parentStart = $parentAttributes['planned_start_day'] ?? null;
        $parentEnd = $parentAttributes['target_day'] ?? null;
        if ($parentStart && $parentEnd && ($start < $parentStart || $end > $parentEnd)) {
            return $item->entity_type === 'task'
                ? 'Task期間は親の取り組み期間内で設定してください。'
                : '取り組み期間は親のRoadmap期間内で設定してください。';
        }

        return null;
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
            'project' => hash_equals($project->public_id, $item->target_public_id),
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
