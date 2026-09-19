<?php

namespace App\Services;

use App\Exceptions\AiProposalApplyException;
use App\Models\AiProposal;
use App\Models\AiProposalApplyAttempt;
use App\Models\AiProposalItem;
use App\Models\AiProposalItemResult;
use App\Models\AiRequest;
use App\Models\Improvement;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiProposalScopeOneApplier
{
    private const MAX_ATTEMPTS = 3;

    private const PROCESSING_TIMEOUT_MINUTES = 5;

    public function __construct(
        private readonly AiProposalAuthorization $authorization,
        private readonly AiProposalValidator $validator,
        private readonly ProjectPlanSnapshotService $snapshots,
    ) {}

    public function apply(AiProposal $proposal, User $actor): AiProposal
    {
        $this->authorization->authorize($actor, $proposal->project, $proposal);

        if (! config('services.ai.scope_one_apply_enabled', true)) {
            throw ValidationException::withMessages(['proposal' => 'AI提案の適用は一時停止中です。提案と履歴は保持されています。']);
        }

        try {
            $attempt = $this->prepareAttempt($proposal, $actor);
        } catch (AiProposalApplyException $error) {
            throw ValidationException::withMessages(['proposal' => $error->getMessage()]);
        }
        if ($attempt->status === AiProposalApplyAttempt::STATUS_APPLIED) {
            return $proposal->fresh(['items', 'applyAttempts.itemResults']);
        }

        $completed = [];
        $currentItemId = null;
        $failedStep = 'preflight';
        try {
            DB::transaction(function () use ($proposal, $actor, $attempt, &$completed, &$currentItemId, &$failedStep): void {
                $locked = AiProposal::query()->lockForUpdate()->with(['project', 'items'])->findOrFail($proposal->id);
                if ($locked->status !== AiProposal::STATUS_APPROVED) {
                    throw $this->failure('not_approved', '内容確認後に変更を適用してください。');
                }
                $this->authorization->authorize($actor, $locked->project, $locked);
                $project = $locked->project()->lockForUpdate()->firstOrFail();
                $locked->setRelation('project', $project);

                if ((int) $project->plan_version !== (int) $locked->expected_project_version
                    || (int) $locked->approved_project_version !== (int) $locked->expected_project_version) {
                    throw $this->failure('conflict', '他の変更があるため反映していません。最新状態から再提案してください。');
                }
                $hash = AiProposalContract::proposalHash($locked);
                if (! hash_equals((string) $locked->content_hash, $hash)
                    || ! hash_equals((string) $locked->approved_content_hash, $hash)) {
                    throw $this->failure('approval_stale', '確認後に提案内容が変わったため反映していません。');
                }

                $failedStep = 'contract_validation';
                $locked = $this->validator->validate($locked)->load(['project', 'items']);
                if ($locked->items->isEmpty() || $locked->items->contains('validation_status', AiProposalValidator::STATUS_INVALID)) {
                    throw $this->failure('contract_mismatch', '適用できない内容があります。最新状態から再提案してください。');
                }

                $failedStep = 'optimistic_lock';
                foreach ($locked->items->sortBy([['entity_type', 'asc'], ['target_public_id', 'asc'], ['id', 'asc']]) as $item) {
                    $this->assertItemCurrent($project, $item);
                }

                $previous = $this->snapshots->capture($project);
                $references = [];
                foreach ($locked->items->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $item) {
                    $currentItemId = $item->id;
                    $failedStep = 'item_apply';
                    $this->beforeItemApply($item);
                    $model = $item->operation === AiProposalItem::OPERATION_CREATE
                        ? $this->create($locked, $item, $actor, $references)
                        : $this->update($locked, $item);
                    if ($item->reference_key) {
                        $references[$item->reference_key] = $model;
                    }
                    $model->refresh();
                    $item->update(['applied_entity_public_id' => $model->public_id]);
                    $completed[$item->id] = $model->public_id;
                    $currentItemId = null;
                }

                foreach ($locked->items as $item) {
                    $applied = $this->target($project->fresh(), $item->entity_type, $item->applied_entity_public_id, true)
                        ?? throw $this->failure('history_mismatch', '適用結果を安全に記録できませんでした。');
                    $item->update(['applied_version' => $applied->plan_version]);
                }

                $failedStep = 'history';
                $version = $this->snapshots->storeAppliedVersion($project->fresh(), $locked, $actor, $previous);
                $locked->update([
                    'status' => AiProposal::STATUS_APPLIED,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                    'applied_at' => now(),
                    'applied_plan_version_id' => $version->id,
                    'failure_reason' => null,
                ]);
                $locked->aiRequest?->update(['status' => AiRequest::STATUS_COMPLETED, 'completed_at' => now()]);
                foreach ($completed as $itemId => $publicId) {
                    AiProposalItemResult::create([
                        'ai_proposal_apply_attempt_id' => $attempt->id,
                        'ai_proposal_item_id' => $itemId,
                        'status' => AiProposalItemResult::STATUS_APPLIED,
                        'applied_entity_public_id' => $publicId,
                    ]);
                }
                $attempt->update([
                    'status' => AiProposalApplyAttempt::STATUS_APPLIED,
                    'applied_items_count' => count($completed),
                    'finished_at' => now(),
                ]);
            }, 3);
        } catch (Throwable $error) {
            [$code, $message, $retryable] = $this->classify($error);
            $this->recordFailure($proposal, $attempt, $completed, $currentItemId, $failedStep, $code, $message, $retryable);
            if ($error instanceof AiProposalApplyException) {
                throw ValidationException::withMessages(['proposal' => $message]);
            }
            throw $error;
        }

        return $proposal->fresh(['items', 'applyAttempts.itemResults']);
    }

    /** Test seam for exercising transaction rollback after a preceding item succeeds. */
    protected function beforeItemApply(AiProposalItem $item): void
    {
        // Intentionally empty in production.
    }

    private function prepareAttempt(AiProposal $proposal, User $actor): AiProposalApplyAttempt
    {
        return DB::transaction(function () use ($proposal, $actor): AiProposalApplyAttempt {
            $locked = AiProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $attempts = $locked->applyAttempts()->lockForUpdate()->orderBy('attempt_number')->get();
            $applied = $attempts->firstWhere('status', AiProposalApplyAttempt::STATUS_APPLIED);
            if ($applied) {
                return $applied;
            }
            $latest = $attempts->last();
            if ($locked->status === AiProposal::STATUS_APPLIED) {
                if ($latest?->status !== AiProposalApplyAttempt::STATUS_PROCESSING) {
                    throw $this->failure('history_mismatch', '適用済み結果の履歴を確認できません。管理者へ確認してください。');
                }
                $latest->update([
                    'status' => AiProposalApplyAttempt::STATUS_APPLIED,
                    'applied_items_count' => $locked->items()->whereNotNull('applied_entity_public_id')->count(),
                    'finished_at' => now(),
                ]);

                return $latest;
            }
            if ($locked->status !== AiProposal::STATUS_APPROVED) {
                throw $this->failure('not_approved', '内容確認後の提案だけを適用できます。');
            }

            if ($latest?->status === AiProposalApplyAttempt::STATUS_PROCESSING) {
                if ($latest->started_at->gt(now()->subMinutes(self::PROCESSING_TIMEOUT_MINUTES))) {
                    throw $this->failure('processing', '変更を適用中です。結果を再確認してください。', true);
                }
                $latest->update([
                    'status' => AiProposalApplyAttempt::STATUS_FAILED,
                    'error_code' => 'processing_interrupted',
                    'retryable' => true,
                    'error_message' => '前回の処理が完了しなかったため、安全に再試行できます。',
                    'finished_at' => now(),
                ]);
                foreach ($locked->items as $item) {
                    $latest->itemResults()->updateOrCreate(
                        ['ai_proposal_item_id' => $item->id],
                        [
                            'status' => AiProposalItemResult::STATUS_NOT_RUN,
                            'failed_step' => 'processing_recovery',
                            'error_code' => 'processing_interrupted',
                            'error_message' => '前回処理の完了を確認できなかったため、業務変更を再確認して再試行します。',
                        ],
                    );
                }
            } elseif ($latest && ! $latest->retryable) {
                throw $this->failure((string) ($latest->error_code ?: 'retry_not_allowed'), 'この失敗は再試行できません。最新状態から再提案してください。');
            }

            $number = $attempts->count() + 1;
            if ($number > self::MAX_ATTEMPTS) {
                throw $this->failure('retry_exhausted', '再試行上限に達しました。最新状態から再提案してください。');
            }

            return $locked->applyAttempts()->create([
                'actor_id' => $actor->id,
                'attempt_number' => $number,
                'idempotency_key' => 'apply:'.$locked->public_id.($number > 1 ? ':retry:'.$number : ''),
                'status' => AiProposalApplyAttempt::STATUS_PROCESSING,
                'started_at' => now(),
            ]);
        }, 3);
    }

    private function assertItemCurrent($project, AiProposalItem $item): void
    {
        if (! AiProposalContract::supports($item)) {
            throw $this->failure('contract_mismatch', 'Scope 1で許可されていない変更です。');
        }
        if ($item->operation === AiProposalItem::OPERATION_CREATE) {
            $parentType = AiProposalContract::parentType($item->entity_type);
            if ($parentType && ! $this->proposalReferenceExists($item->proposal, $item->parent_reference)) {
                $parent = $this->target($project, $parentType, $item->parent_reference, true);
                if (! $parent) {
                    throw $this->failure('dependency_mismatch', '親Relationが提案後に変更されています。');
                }
            }

            return;
        }

        $model = $this->target($project, $item->entity_type, $item->target_public_id, true);
        if (! $model || (int) $model->plan_version !== (int) $item->expected_version) {
            throw $this->failure('conflict', '対象が提案後に変更されています。最新状態から再提案してください。');
        }
        if (AiProposalContract::snapshot($model, $item->entity_type) !== ($item->before ?? [])) {
            throw $this->failure('conflict', '変更前の内容が一致しません。最新状態から再提案してください。');
        }
    }

    private function create(AiProposal $proposal, AiProposalItem $item, User $actor, array $references): Model
    {
        $attributes = $item->after;
        $parent = $this->resolveParent($proposal, $item, $references);

        return match ($item->entity_type) {
            'roadmap' => Roadmap::create($attributes + [
                'organization_id' => $proposal->organization_id,
                'workspace_id' => $proposal->workspace_id,
                'project_id' => $proposal->project_id,
                'status' => Roadmap::STATUS_DRAFT,
                'sort_order' => ((int) $proposal->project->roadmaps()->max('sort_order')) + 1,
                'created_by' => $actor->id,
            ]),
            'improvement' => Improvement::create($attributes + [
                'organization_id' => $proposal->organization_id,
                'workspace_id' => $proposal->workspace_id,
                'project_id' => $proposal->project_id,
                'roadmap_id' => $parent?->id,
                'status' => Improvement::STATUS_PROPOSED,
                'visibility' => Improvement::VISIBILITY_INTERNAL,
                'roadmap_sort_order' => ((int) $parent?->improvements()->max('roadmap_sort_order')) + 1,
                'proposed_by' => $actor->id,
                'assigned_to' => null,
            ]),
            'task' => Task::create($attributes + [
                'organization_id' => $proposal->organization_id,
                'workspace_id' => $proposal->workspace_id,
                'project_id' => $proposal->project_id,
                'improvement_id' => $parent?->id,
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_NORMAL,
                'sort_order' => ((int) $parent?->tasks()->max('sort_order')) + 1,
                'created_by' => $actor->id,
                'assigned_to' => null,
            ]),
            default => throw $this->failure('contract_mismatch', '新規作成できない対象です。'),
        };
    }

    private function update(AiProposal $proposal, AiProposalItem $item): Model
    {
        $model = $this->target($proposal->project, $item->entity_type, $item->target_public_id, true)
            ?? throw $this->failure('conflict', '更新対象が見つかりません。');
        $model->update($item->after);

        return $model;
    }

    private function resolveParent(AiProposal $proposal, AiProposalItem $item, array $references): ?Model
    {
        $parentType = AiProposalContract::parentType($item->entity_type);
        if (! $parentType) {
            return null;
        }
        if (isset($references[$item->parent_reference])) {
            return $references[$item->parent_reference];
        }

        return $this->target($proposal->project, $parentType, $item->parent_reference, true)
            ?? throw $this->failure('dependency_mismatch', '有効な親Relationを解決できません。');
    }

    private function proposalReferenceExists(AiProposal $proposal, ?string $reference): bool
    {
        return $reference && $proposal->items->contains('reference_key', $reference);
    }

    private function target($project, string $type, ?string $publicId, bool $lock = false): ?Model
    {
        if (! $publicId) {
            return null;
        }
        if ($type === 'project') {
            return hash_equals((string) $project->public_id, $publicId) ? $project : null;
        }
        $query = match ($type) {
            'roadmap' => $project->roadmaps(),
            'improvement' => $project->improvements(),
            'task' => $project->tasks(),
            default => null,
        };
        if (! $query) {
            return null;
        }
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->where('public_id', $publicId)->first();
    }

    private function recordFailure(
        AiProposal $proposal,
        AiProposalApplyAttempt $attempt,
        array $completed,
        ?int $currentItemId,
        string $failedStep,
        string $code,
        string $message,
        bool $retryable,
    ): void {
        DB::transaction(function () use ($proposal, $attempt, $completed, $currentItemId, $failedStep, $code, $message, $retryable): void {
            $attempt->update([
                'status' => in_array($code, ['conflict', 'approval_stale', 'dependency_mismatch'], true)
                    ? AiProposalApplyAttempt::STATUS_CONFLICTED
                    : AiProposalApplyAttempt::STATUS_FAILED,
                'error_code' => $code,
                'retryable' => $retryable,
                'error_message' => $message,
                'finished_at' => now(),
            ]);
            foreach ($proposal->items as $item) {
                $status = isset($completed[$item->id])
                    ? AiProposalItemResult::STATUS_ROLLED_BACK
                    : ($currentItemId === $item->id ? AiProposalItemResult::STATUS_FAILED : AiProposalItemResult::STATUS_NOT_RUN);
                AiProposalItemResult::updateOrCreate(
                    ['ai_proposal_apply_attempt_id' => $attempt->id, 'ai_proposal_item_id' => $item->id],
                    [
                        'status' => $status,
                        'failed_step' => $status === AiProposalItemResult::STATUS_FAILED ? $failedStep : null,
                        'error_code' => $code,
                        'error_message' => $message,
                    ],
                );
            }
        });
    }

    /** @return array{string, string, bool} */
    private function classify(Throwable $error): array
    {
        if ($error instanceof AiProposalApplyException) {
            return [$error->errorCode, $error->getMessage(), $error->retryable];
        }
        if ($error instanceof AuthorizationException) {
            return ['permission_denied', '現在の権限では変更を適用できません。', false];
        }
        if ($error instanceof ModelNotFoundException) {
            return ['tenant_or_target_not_found', '対象を安全に確認できないため反映していません。', false];
        }
        if ($error instanceof ValidationException) {
            return ['validation_failed', $error->validator->errors()->first('proposal') ?: '提案内容を検証できませんでした。', false];
        }
        if ($error instanceof QueryException) {
            return ['temporary_database_error', '一時的なDB障害で反映できませんでした。', true];
        }

        return ['internal_error', '内部処理を完了できなかったため反映していません。', false];
    }

    private function failure(string $code, string $message, bool $retryable = false): AiProposalApplyException
    {
        return new AiProposalApplyException($code, $message, $retryable);
    }
}
