<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Project;
use App\Models\User;
use App\Support\AiTextIntegrity;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use App\Services\ProjectExecution\ProjectExecutionProposalContract;

class AiProposalValidator
{
    public const STATUS_VALID = 'valid';

    public const STATUS_INVALID = 'invalid';

    public function __construct(private readonly ProjectExecutionAccess $executionAccess) {}

    public function validate(AiProposal $proposal): AiProposal
    {
        $proposal->loadMissing(['project', 'items']);
        $proposalErrors = $this->proposalErrors($proposal);

        foreach ($proposal->items as $item) {
            $errors = array_values(array_unique([...$proposalErrors, ...$this->itemErrors($proposal, $item)]));
            $item->update([
                'validation_status' => $errors === [] ? self::STATUS_VALID : self::STATUS_INVALID,
                'validation_message' => $errors === [] ? null : implode("\n", $errors),
            ]);
        }

        return $proposal->fresh('items');
    }

    /** @return array<int, string> */
    public function proposalErrors(AiProposal $proposal): array
    {
        $proposal->loadMissing(['project', 'items']);
        $errors = [];
        $scopeEight = $proposal->contract_version === ProjectExecutionProposalContract::VERSION;
        $expectedCapability = $scopeEight ? ProjectExecutionProposalContract::CAPABILITY : AiProposalContract::CAPABILITY;
        $expectedRisk = $scopeEight ? ProjectExecutionProposalContract::RISK_LEVEL : AiProposalContract::RISK_LEVEL;
        $expectedPolicy = $scopeEight ? ProjectExecutionProposalContract::APPROVAL_POLICY : AiProposalContract::APPROVAL_POLICY;
        if (! in_array($proposal->contract_version, [AiProposalContract::VERSION, ProjectExecutionProposalContract::VERSION], true)) {
            $errors[] = 'この提案は旧形式または未対応形式です。最新状態から再提案してください。';
        }
        if ($proposal->capability !== $expectedCapability) {
            $errors[] = '未対応のAI変更Capabilityです。';
        }
        if ($proposal->risk_level !== $expectedRisk) {
            $errors[] = '未対応のRisk Levelです。';
        }
        if ($proposal->approval_policy !== $expectedPolicy) {
            $errors[] = '未対応の承認Policyです。';
        }
        if ($proposal->mode !== AiProposal::MODE_DIFFERENTIAL) {
            $errors[] = 'Scope 1ではTimeline全置換を適用できません。';
        }
        if (! $proposal->expected_project_version) {
            $errors[] = 'Projectの期待版がありません。最新状態から再提案してください。';
        }
        if ((int) $proposal->project?->plan_version !== (int) $proposal->expected_project_version) {
            $errors[] = 'Project計画が提案後に変更されています。最新状態から再提案してください。';
        }
        if ($proposal->items->isEmpty() || $proposal->items->count() > 100) {
            $errors[] = '提案項目は1〜100件で指定してください。';
        }
        if ($proposal->content_hash && ! hash_equals((string) $proposal->content_hash, AiProposalContract::proposalHash($proposal))) {
            $errors[] = '提案内容が作成後に変更されています。再確認が必要です。';
        }

        $referenceKeys = $proposal->items->pluck('reference_key')->filter();
        if ($referenceKeys->duplicates()->isNotEmpty()) {
            $errors[] = '提案内の参照キーは重複できません。';
        }

        $seenReferences = [];
        $seenTargets = [];
        foreach ($proposal->items->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $item) {
            foreach ($item->depends_on ?? [] as $dependency) {
                if (! isset($seenReferences[$dependency])) {
                    $errors[] = '依存先は同じ提案内で先に定義された参照キーである必要があります。';
                }
            }
            if ($item->parent_reference && isset($seenReferences[$item->parent_reference])
                && ! in_array($item->parent_reference, $item->depends_on ?? [], true)) {
                $errors[] = '新規親への参照はdepends_onにも含めてください。';
            }
            if ($item->reference_key) {
                $seenReferences[$item->reference_key] = true;
            }

            if ($item->operation === AiProposalItem::OPERATION_UPDATE && $item->target_public_id) {
                $targetKey = $item->entity_type.':'.$item->target_public_id;
                if (isset($seenTargets[$targetKey])) {
                    $errors[] = '同じ対象を1つの提案で複数回更新できません。';
                }
                $seenTargets[$targetKey] = true;
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array<int, string> */
    public function itemErrors(AiProposal $proposal, AiProposalItem $item): array
    {
        $project = $proposal->project;
        $attributes = $item->attributes ?? [];
        $allowed = AiProposalContract::allowedAttributes($item->entity_type, $proposal->contract_version);
        $errors = [];

        if (! $item->public_id) {
            $errors[] = '安定したProposal Item IDがありません。';
        }
        if (! AiProposalContract::supports($item, $proposal->contract_version)) {
            $errors[] = 'Scope 1で許可されていない対象または操作です。';
        }
        if (! $item->expected_version) {
            $errors[] = '対象の期待版がありません。';
        }
        if (($item->after ?? $attributes) !== $attributes) {
            $errors[] = '変更後データと適用データが一致しません。';
        }
        if ($attributes === []) {
            $errors[] = '変更項目を1件以上指定してください。';
        }
        if (AiTextIntegrity::containsMojibake($attributes)) {
            $errors[] = AiTextIntegrity::ERROR_MESSAGE;
        }

        $unknown = array_diff(array_keys($attributes), $allowed);
        if ($unknown !== []) {
            $errors[] = '許可されていない項目: '.implode(', ', $unknown);
        }

        $validator = Validator::make($attributes, $this->rules($item));
        if ($validator->fails()) {
            $errors = [...$errors, ...$validator->errors()->all()];
        }

        if ($proposal->contract_version === ProjectExecutionProposalContract::VERSION
            && $item->entity_type === 'task') {
            $errors = [...$errors, ...$this->executionMemberErrors($proposal, $attributes)];
        }

        if ($item->operation === AiProposalItem::OPERATION_CREATE) {
            if ($item->target_public_id) {
                $errors[] = '新規作成には既存対象IDを指定できません。';
            }
            if ($item->before !== null) {
                $errors[] = '新規作成の変更前データは空である必要があります。';
            }
            if ((int) $item->expected_version !== (int) $proposal->expected_project_version) {
                $errors[] = '新規作成の期待版がProject計画版と一致しません。';
            }
            if ($relationError = $this->createRelationError($proposal, $item)) {
                $errors[] = $relationError;
            }
        }

        if ($item->operation === AiProposalItem::OPERATION_UPDATE) {
            if (! $item->target_public_id) {
                $errors[] = '更新対象IDがありません。';
            }
            if ($item->reference_key || $item->parent_reference || ($item->depends_on ?? []) !== []) {
                $errors[] = '更新では親移動や提案内参照を指定できません。';
            }
            $target = $this->target($project, $item->entity_type, $item->target_public_id);
            if (! $target) {
                $errors[] = '更新対象がこのProject内に存在しません。';
            } else {
                if ((int) $target->plan_version !== (int) $item->expected_version) {
                    $errors[] = '更新対象が提案後に変更されています。';
                }
                if (AiProposalContract::snapshot($target, $item->entity_type, $proposal->contract_version) !== ($item->before ?? [])) {
                    $errors[] = '変更前データが現在の対象と一致しません。';
                }
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array<int, string> */
    private function executionMemberErrors(AiProposal $proposal, array $attributes): array
    {
        $errors = [];
        if (array_key_exists('assigned_to', $attributes) && is_numeric($attributes['assigned_to'])) {
            $assignee = User::find((int) $attributes['assigned_to']);
            if (! $assignee || ! $this->executionAccess->isExecutionMember($assignee, $proposal->project)) {
                $errors[] = 'Action assignee must be an active explicit execution member of this Project.';
            }
        }
        if (array_key_exists('reviewer_user_id', $attributes) && $attributes['reviewer_user_id'] !== null
            && is_numeric($attributes['reviewer_user_id'])) {
            $reviewer = User::find((int) $attributes['reviewer_user_id']);
            if (! $reviewer || ! $this->executionAccess->canBeReviewer($reviewer, $proposal->project)) {
                $errors[] = 'Action reviewer must be an active explicit reviewer-capable member of this Project.';
            }
        }

        return $errors;
    }

    private function rules(AiProposalItem $item): array
    {
        $title = $item->operation === AiProposalItem::OPERATION_CREATE
            ? ['required', 'string', 'max:255']
            : ['sometimes', 'string', 'max:255'];

        if ($item->proposal?->contract_version === ProjectExecutionProposalContract::VERSION) {
            return match ($item->entity_type) {
                'project' => ['purpose' => ['sometimes', 'string', 'max:5000'], 'expected_outcome' => ['sometimes', 'string', 'max:5000'], 'current_state' => ['sometimes', 'nullable', 'string', 'max:5000']],
                'roadmap' => ['title' => $title, 'purpose' => ['sometimes', 'nullable', 'string', 'max:10000']],
                'improvement' => ['title' => $title, 'theme_description' => ['sometimes', 'nullable', 'string', 'max:10000']],
                'task' => [
                    'title' => $title, 'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
                    'done_condition' => [$item->operation === AiProposalItem::OPERATION_CREATE ? 'required' : 'sometimes', 'string', 'max:10000'],
                    'assigned_to' => [$item->operation === AiProposalItem::OPERATION_CREATE ? 'required' : 'sometimes', 'integer'],
                    'reviewer_user_id' => ['sometimes', 'nullable', 'integer', 'different:assigned_to'],
                    'due_date' => ['sometimes', 'nullable', 'date'],
                ],
                default => [],
            };
        }

        return match ($item->entity_type) {
            'project' => [
                'summary' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'current_state' => ['sometimes', 'nullable', 'string', 'max:5000'],
                'desired_future_state' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ],
            'roadmap' => ['title' => $title, 'purpose' => ['sometimes', 'nullable', 'string', 'max:10000']],
            'improvement' => [
                'title' => $title,
                'current_state' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'desired_state' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'problem' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'hypothesis' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'action' => ['sometimes', 'nullable', 'string', 'max:10000'],
                'next_action' => ['sometimes', 'nullable', 'string', 'max:10000'],
            ],
            'task' => ['title' => $title, 'description' => ['sometimes', 'nullable', 'string', 'max:10000']],
            default => [],
        };
    }

    private function createRelationError(AiProposal $proposal, AiProposalItem $item): ?string
    {
        $expectedParentType = AiProposalContract::parentType($item->entity_type, $proposal->contract_version, $item->parent_reference, $proposal->project->public_id);
        if (! $expectedParentType) {
            return $item->parent_reference ? 'この対象には親参照を指定できません。' : null;
        }
        if (! $item->parent_reference) {
            return '新規作成には有効な親参照が必要です。';
        }

        $parentItem = $proposal->items
            ->where('sort_order', '<', $item->sort_order)
            ->firstWhere('reference_key', $item->parent_reference);
        if ($parentItem) {
            return $parentItem->entity_type === $expectedParentType
                && $parentItem->operation === AiProposalItem::OPERATION_CREATE
                ? null
                : '提案内の親参照の種類または操作が不正です。';
        }

        return $this->target($proposal->project, $expectedParentType, $item->parent_reference)
            ? null
            : '親参照がこのProject内に存在しないか、親項目より先に定義されていません。';
    }

    private function target(Project $project, string $type, ?string $publicId): ?Model
    {
        if (! $publicId) {
            return null;
        }

        return match ($type) {
            'project' => hash_equals((string) $project->public_id, $publicId) ? $project : null,
            'roadmap' => $project->roadmaps()->where('public_id', $publicId)->first(),
            'improvement' => $project->improvements()->where('public_id', $publicId)->first(),
            'task' => $project->tasks()->where('public_id', $publicId)->first(),
            default => null,
        };
    }
}
