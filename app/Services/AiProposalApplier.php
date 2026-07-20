<?php

namespace App\Services;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Improvement;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiProposalApplier
{
    public function __construct(
        private readonly AiProposalValidator $validator,
        private readonly ScheduleIntegrityService $scheduleIntegrity,
    ) {}

    public function apply(AiProposal $proposal, User $reviewer): AiProposal
    {
        $proposal = $this->validator->validate($proposal);

        if ($proposal->status !== AiProposal::STATUS_PENDING) {
            throw ValidationException::withMessages(['proposal' => '承認待ちの提案だけを反映できます。']);
        }

        if ($proposal->items->isEmpty() || $proposal->items->contains('validation_status', AiProposalValidator::STATUS_INVALID)) {
            throw ValidationException::withMessages(['proposal' => '検証エラーを含む提案は反映できません。']);
        }

        return DB::transaction(function () use ($proposal, $reviewer): AiProposal {
            $locked = AiProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            if ($locked->status !== AiProposal::STATUS_PENDING) {
                throw ValidationException::withMessages(['proposal' => 'この提案はすでに処理されています。']);
            }

            $invalidBefore = $this->scheduleIntegrity->inspect($locked->project->fresh())['invalid'];

            $references = [];
            $items = $locked->items()->orderBy('sort_order')->orderBy('id')->get()
                ->sortBy(fn (AiProposalItem $item) => $item->operation === AiProposalItem::OPERATION_DELETE
                    ? 100 + match ($item->entity_type) { 'task' => 1, 'improvement' => 2, 'roadmap' => 3 }
                    : 0)
                ->values();

            foreach ($items as $item) {
                $model = match ($item->operation) {
                    AiProposalItem::OPERATION_CREATE => $this->create($locked, $item, $reviewer, $references),
                    AiProposalItem::OPERATION_UPDATE => $this->update($locked, $item),
                    AiProposalItem::OPERATION_DELETE => $this->delete($locked, $item),
                };

                if ($item->reference_key) {
                    $references[$item->reference_key] = $model;
                }
            }

            $invalidAfter = $this->scheduleIntegrity->inspect($locked->project->fresh())['invalid'];
            $newInvalid = $invalidAfter->diff($invalidBefore);
            if ($newInvalid->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'proposal' => 'この提案を反映すると日程が上位期間から外れます。'.PHP_EOL.$newInvalid->implode(PHP_EOL),
                ]);
            }

            $locked->update([
                'status' => AiProposal::STATUS_APPLIED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'applied_at' => now(),
                'failure_reason' => null,
            ]);
            $locked->aiRequest?->update(['status' => \App\Models\AiRequest::STATUS_COMPLETED, 'completed_at' => now()]);

            return $locked->fresh(['items', 'reviewer']);
        });
    }

    private function create(AiProposal $proposal, AiProposalItem $item, User $reviewer, array $references): Model
    {
        $attributes = $item->attributes;

        return match ($item->entity_type) {
            'roadmap' => Roadmap::create(Arr::only($attributes, ['title', 'purpose', 'status', 'sort_order', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day']) + [
                'organization_id' => $proposal->organization_id,
                'workspace_id' => $proposal->workspace_id,
                'project_id' => $proposal->project_id,
                'status' => $attributes['status'] ?? Roadmap::STATUS_DRAFT,
                'created_by' => $reviewer->id,
            ]),
            'improvement' => Improvement::create(Arr::only($attributes, ['title', 'current_state', 'desired_state', 'problem', 'hypothesis', 'action', 'result', 'impact', 'next_action', 'status', 'visibility', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day']) + [
                'organization_id' => $proposal->organization_id,
                'workspace_id' => $proposal->workspace_id,
                'project_id' => $proposal->project_id,
                'roadmap_id' => $this->parentId($proposal, $item, $references, 'roadmap', $attributes['roadmap_public_id'] ?? null),
                'status' => $attributes['status'] ?? Improvement::STATUS_PROPOSED,
                'visibility' => $attributes['visibility'] ?? Improvement::VISIBILITY_INTERNAL,
                'proposed_by' => $reviewer->id,
                'assigned_to' => $reviewer->id,
            ]),
            'task' => Task::create(Arr::only($attributes, ['title', 'description', 'status', 'priority', 'planned_start_date', 'due_date', 'planned_start_day', 'due_day']) + [
                'organization_id' => $proposal->organization_id,
                'workspace_id' => $proposal->workspace_id,
                'project_id' => $proposal->project_id,
                'improvement_id' => $this->parentId($proposal, $item, $references, 'improvement', $attributes['improvement_public_id'] ?? null),
                'status' => $attributes['status'] ?? Task::STATUS_TODO,
                'priority' => $attributes['priority'] ?? Task::PRIORITY_NORMAL,
                'created_by' => $reviewer->id,
                'assigned_to' => $reviewer->id,
            ]),
        };
    }

    private function update(AiProposal $proposal, AiProposalItem $item): Model
    {
        $attributes = $item->attributes;
        $model = match ($item->entity_type) {
            'roadmap' => $proposal->project->roadmaps()->where('public_id', $item->target_public_id)->firstOrFail(),
            'improvement' => $proposal->project->improvements()->where('public_id', $item->target_public_id)->firstOrFail(),
            'task' => $proposal->project->tasks()->where('public_id', $item->target_public_id)->firstOrFail(),
        };

        $allowed = match ($item->entity_type) {
            'roadmap' => ['title', 'purpose', 'status', 'sort_order', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'],
            'improvement' => ['title', 'current_state', 'desired_state', 'problem', 'hypothesis', 'action', 'result', 'impact', 'next_action', 'status', 'visibility', 'planned_start_date', 'target_date', 'planned_start_day', 'target_day'],
            'task' => ['title', 'description', 'status', 'priority', 'planned_start_date', 'due_date', 'planned_start_day', 'due_day'],
        };
        $model->update(Arr::only($attributes, $allowed));

        if ($model instanceof Task && array_key_exists('status', $attributes)) {
            $model->update(['completed_at' => $attributes['status'] === Task::STATUS_DONE ? ($model->completed_at ?? now()) : null]);
        }

        return $model;
    }

    private function delete(AiProposal $proposal, AiProposalItem $item): Model
    {
        $model = match ($item->entity_type) {
            'roadmap' => $proposal->project->roadmaps()->where('public_id', $item->target_public_id)->firstOrFail(),
            'improvement' => $proposal->project->improvements()->where('public_id', $item->target_public_id)->firstOrFail(),
            'task' => $proposal->project->tasks()->where('public_id', $item->target_public_id)->firstOrFail(),
        };

        $model->delete();

        return $model;
    }

    private function parentId(AiProposal $proposal, AiProposalItem $item, array $references, string $type, ?string $publicId): ?int
    {
        if ($item->parent_reference) {
            return $references[$item->parent_reference]->id;
        }
        if (! $publicId) {
            return null;
        }
        return match ($type) {
            'roadmap' => $proposal->project->roadmaps()->where('public_id', $publicId)->value('id'),
            'improvement' => $proposal->project->improvements()->where('public_id', $publicId)->value('id'),
        };
    }
}
