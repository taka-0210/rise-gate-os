<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonHandoffRelation;
use App\Models\AiProposal;
use App\Models\AiProposalApplyAttempt;
use App\Models\AiProposalItem;
use App\Models\AiProposalItemResult;
use App\Models\AiProposalUndo;
use App\Models\BusinessDomain;
use App\Models\Capture;
use App\Models\OrganizationAiPolicy;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\BusinessDomain\BusinessDomainWriter;
use App\Services\Capture\CaptureAccess;
use App\Services\Capture\CaptureWriter;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiCommonUnitAdapter
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly CaptureAccess $captureAccess,
        private readonly CaptureWriter $captureWriter,
        private readonly ProjectExecutionAccess $projectAccess,
        private readonly ProjectExecutionWriter $projectWriter,
        private readonly BusinessDomainAccess $domainAccess,
        private readonly BusinessDomainWriter $domainWriter,
        private readonly AiCommonProposalLineage $lineage,
    ) {}

    public function approve(AiProposal $proposal, User $actor): AiProposal
    {
        return DB::transaction(function () use ($proposal, $actor): AiProposal {
            $locked = AiProposal::query()->with(['items', 'commonConversation.organization'])->lockForUpdate()->findOrFail($proposal->id);
            $this->assertShape($locked);
            $this->common->authorizeConversation($actor, $locked->commonConversation->organization, $locked->commonConversation);
            $this->authorizeOperation($actor, $locked, false);
            if ($locked->status !== AiProposal::STATUS_PENDING) {
                throw ValidationException::withMessages(['proposal' => 'この提案は承認待ちではありません。']);
            }
            $hash = AiCommonProposalContract::proposalHash($locked);
            if (! hash_equals((string) $locked->content_hash, $hash)) {
                throw ValidationException::withMessages(['proposal' => '提案内容が変更されています。']);
            }
            $locked->update([
                'status' => AiProposal::STATUS_APPROVED,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approved_content_hash' => $hash,
            ]);

            return $locked->fresh(['items', 'approver']);
        }, 3);
    }

    public function apply(AiProposal $proposal, User $actor): AiProposal
    {
        $proposal->loadMissing(['items', 'commonConversation.organization']);
        $this->common->authorizeConversation($actor, $proposal->commonConversation->organization, $proposal->commonConversation);
        $this->authorizeOperation($actor, $proposal, true);

        $appliedAttempt = $proposal->applyAttempts()->where('status', AiProposalApplyAttempt::STATUS_APPLIED)->first();
        if ($proposal->status === AiProposal::STATUS_APPLIED && $appliedAttempt) {
            $this->authorizeAppliedResult($actor, $proposal);

            return $proposal->fresh(['items', 'applyAttempts.itemResults', 'commonHandoff']);
        }
        $attempt = $this->newAttempt($proposal, $actor);
        if ($attempt->status === AiProposalApplyAttempt::STATUS_APPLIED) {
            $current = $proposal->fresh(['items', 'commonConversation.organization']);
            $this->authorizeAppliedResult($actor, $current);

            return $current->load(['applyAttempts.itemResults', 'commonHandoff']);
        }
        try {
            DB::transaction(function () use ($proposal, $actor, $attempt): void {
                $locked = AiProposal::query()->with(['items', 'commonConversation.organization'])->lockForUpdate()->findOrFail($proposal->id);
                $this->assertShape($locked);
                if ($locked->status === AiProposal::STATUS_APPLIED
                    && $attempt->fresh()->status === AiProposalApplyAttempt::STATUS_APPLIED) {
                    return;
                }
                if ($locked->status !== AiProposal::STATUS_APPROVED) {
                    throw ValidationException::withMessages(['proposal' => '承認済みの提案だけを適用できます。']);
                }
                $this->authorizeOperation($actor, $locked, true);
                $hash = AiCommonProposalContract::proposalHash($locked);
                if (! hash_equals((string) $locked->content_hash, $hash)
                    || ! hash_equals((string) $locked->approved_content_hash, $hash)) {
                    throw ValidationException::withMessages(['proposal' => '承認後に提案内容が変更されています。']);
                }
                $item = $locked->items->sole();
                $target = $this->applyItem($actor, $locked, $item);
                $this->afterWriterApply($target);
                $publicId = (string) $target->public_id;
                $version = (int) ($target->version ?? $target->plan_version ?? 1);
                $item->update(['applied_entity_public_id' => $publicId, 'applied_version' => $version]);
                AiProposalItemResult::query()->create([
                    'ai_proposal_apply_attempt_id' => $attempt->id,
                    'ai_proposal_item_id' => $item->id,
                    'status' => AiProposalItemResult::STATUS_APPLIED,
                    'applied_entity_public_id' => $publicId,
                ]);
                $attempt->update(['status' => AiProposalApplyAttempt::STATUS_APPLIED, 'applied_items_count' => 1, 'finished_at' => now()]);
                $locked->update(['status' => AiProposal::STATUS_APPLIED, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'applied_at' => now(), 'failure_reason' => null]);
                AiCommonHandoffRelation::query()->where('ai_proposal_id', $locked->id)->update([
                    'target_type' => $locked->target_type,
                    'target_public_id' => $publicId,
                    'applied_at' => now(),
                ]);
            }, 3);
        } catch (Throwable $error) {
            $attempt->update([
                'status' => AiProposalApplyAttempt::STATUS_FAILED,
                'error_code' => $error instanceof ValidationException ? 'validation_failed' : 'writer_failed',
                'retryable' => true,
                'error_message' => '変更を適用できませんでした。現在状態を確認してください。',
                'finished_at' => now(),
            ]);
            throw $error;
        }

        return $proposal->fresh(['items', 'applyAttempts.itemResults', 'commonHandoff']);
    }

    public function undo(AiProposal $proposal, User $actor): AiProposalUndo
    {
        $proposal->loadMissing(['items', 'commonConversation.organization']);
        $this->assertShape($proposal);
        if ($proposal->status !== AiProposal::STATUS_APPLIED) {
            throw ValidationException::withMessages(['undo' => 'Only an applied proposal can be undone.']);
        }
        if ($proposal->items->sole()->operation === AiProposalItem::OPERATION_CREATE) {
            throw ValidationException::withMessages(['undo' => 'Create operations are not undoable.']);
        }
        $this->common->authorizeConversation($actor, $proposal->commonConversation->organization, $proposal->commonConversation);
        $this->authorizeOperation($actor, $proposal, true);
        if ($applied = $proposal->undos()->where('status', AiProposalUndo::STATUS_APPLIED)->latest('id')->first()) {
            return $applied;
        }

        $record = $proposal->undos()->create([
            'actor_id' => $actor->id,
            'status' => AiProposalUndo::STATUS_PROCESSING,
        ]);
        try {
            DB::transaction(function () use ($proposal, $actor, $record): void {
                $locked = AiProposal::query()
                    ->with(['items', 'commonConversation.organization'])
                    ->lockForUpdate()
                    ->findOrFail($proposal->id);
                $this->assertShape($locked);
                if ($locked->status !== AiProposal::STATUS_APPLIED) {
                    throw ValidationException::withMessages(['undo' => 'Only an applied proposal can be undone.']);
                }
                if ($locked->undos()->where('status', AiProposalUndo::STATUS_APPLIED)->whereKeyNot($record->id)->exists()) {
                    throw ValidationException::withMessages(['undo' => 'This proposal was already undone.']);
                }
                $this->common->authorizeConversation($actor, $locked->commonConversation->organization, $locked->commonConversation);
                $this->authorizeOperation($actor, $locked, true);
                $item = $locked->items->sole();
                $target = $this->undoItem($actor, $locked, $item);
                $record->update([
                    'status' => AiProposalUndo::STATUS_APPLIED,
                    'result' => [
                        'restored_items_count' => 1,
                        'target_public_id' => (string) $target->public_id,
                    ],
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }, 3);
        } catch (Throwable $error) {
            $record->update([
                'status' => AiProposalUndo::STATUS_FAILED,
                'error_code' => $error instanceof AuthorizationException ? 'permission_denied' : 'conflict',
                'error_message' => 'The proposal could not be safely undone in its current state.',
            ]);
            throw $error;
        }

        return $record->fresh();
    }

    private function undoItem(User $actor, AiProposal $proposal, AiProposalItem $item): object
    {
        if ($item->operation !== AiProposalItem::OPERATION_UPDATE) {
            throw ValidationException::withMessages(['undo' => 'Only update operations are undoable.']);
        }

        return match ($item->entity_type) {
            AiCommonProposalContract::ACTION_UPDATE => (function () use ($actor, $proposal, $item): Task {
                $action = Task::query()->where('organization_id', $proposal->organization_id)
                    ->where('public_id', $item->applied_entity_public_id)->firstOrFail();
                if ((int) $action->plan_version !== (int) $item->applied_version) {
                    throw ValidationException::withMessages(['undo' => 'The Action changed after apply.']);
                }

                return $this->projectWriter->updateAction(
                    $actor,
                    $action,
                    Arr::only($item->before ?? [], ['title', 'description', 'done_condition', 'due_date']),
                    (int) $action->project->plan_version,
                    'AI Proposal Undo',
                );
            })(),
            AiCommonProposalContract::PROJECT_UPDATE => (function () use ($actor, $proposal, $item): Project {
                $project = Project::query()->where('organization_id', $proposal->organization_id)
                    ->where('public_id', $item->applied_entity_public_id)->firstOrFail();
                if ((int) $project->plan_version !== (int) $item->applied_version) {
                    throw ValidationException::withMessages(['undo' => 'The Project changed after apply.']);
                }

                return $this->projectWriter->updateProject(
                    $actor,
                    $project,
                    Arr::only($item->before ?? [], ['purpose', 'expected_outcome']),
                    (int) $project->plan_version,
                );
            })(),
            AiCommonProposalContract::DOMAIN_UPDATE => (function () use ($actor, $proposal, $item): BusinessDomain {
                $organization = $proposal->commonConversation->organization;
                $domain = BusinessDomain::query()->where('organization_id', $organization->id)
                    ->where('public_id', $item->applied_entity_public_id)
                    ->where('status', BusinessDomain::STATUS_ACTIVE)->firstOrFail();
                if ((int) $domain->version !== (int) $item->applied_version) {
                    throw ValidationException::withMessages(['undo' => 'The Business Domain changed after apply.']);
                }

                return $this->domainWriter->update(
                    $actor,
                    $organization,
                    $domain,
                    Arr::only($item->before ?? [], array_values(array_diff(
                        AiCommonProposalContract::FIELD_MAP[AiCommonProposalContract::DOMAIN_UPDATE],
                        ['reason', 'context_impact_confirmed'],
                    ))),
                    (int) $domain->version,
                    'AI Proposal Undo',
                    AiCommonProposalContract::operationUuid($proposal->common_operation_key.':undo'),
                );
            })(),
            default => throw ValidationException::withMessages(['undo' => 'This operation is not undoable.']),
        };
    }

    private function applyItem(User $actor, AiProposal $proposal, AiProposalItem $item)
    {
        $attributes = AiCommonProposalContract::canonicalAttributes($item->entity_type, $item->after ?? []);

        return match ($item->entity_type) {
            AiCommonProposalContract::CAPTURE_CREATE => $this->applyCapture($actor, $proposal, $attributes),
            AiCommonProposalContract::ACTION_CREATE => $this->applyActionCreate($actor, $proposal, $attributes),
            AiCommonProposalContract::ACTION_UPDATE => $this->applyActionUpdate($actor, $proposal, $item, $attributes),
            AiCommonProposalContract::PROJECT_UPDATE => $this->applyProject($actor, $proposal, $attributes),
            AiCommonProposalContract::DOMAIN_UPDATE => $this->applyDomain($actor, $proposal, $attributes),
            default => throw ValidationException::withMessages(['proposal' => '未対応のUnitです。']),
        };
    }

    private function applyCapture(User $actor, AiProposal $proposal, array $attributes): Capture
    {
        $org = $proposal->commonConversation->organization;
        $recipient = User::query()->findOrFail((int) $attributes['recipient_user_id']);
        if (! $this->captureAccess->canParticipate($actor, $org) || ! $this->captureAccess->canParticipate($recipient, $org)) {
            throw new AuthorizationException;
        }
        $capture = $this->captureWriter->create($actor, $org, [
            'operation_id' => $proposal->common_operation_key,
            ...$attributes,
        ]);
        if (! $this->captureAccess->canRead($actor, $capture)) {
            throw new AuthorizationException;
        }

        return $capture;
    }

    private function applyActionCreate(User $actor, AiProposal $proposal, array $attributes): Task
    {
        $project = Project::query()->where('organization_id', $proposal->organization_id)
            ->where('public_id', $proposal->target_public_id)->firstOrFail();

        return $this->projectWriter->createAction($actor, $project, $attributes, (int) $proposal->expected_target_version);
    }

    private function applyActionUpdate(User $actor, AiProposal $proposal, AiProposalItem $item, array $attributes): Task
    {
        $action = Task::query()->where('organization_id', $proposal->organization_id)
            ->where('public_id', $proposal->target_public_id)->firstOrFail();
        if ((int) $action->plan_version !== (int) $item->expected_version) {
            throw ValidationException::withMessages(['proposal' => 'Actionが更新されています。']);
        }
        $reason = $attributes['reason'] ?? null;
        unset($attributes['reason']);

        return $this->projectWriter->updateAction($actor, $action, $attributes, (int) $proposal->expected_target_version, $reason);
    }

    private function applyProject(User $actor, AiProposal $proposal, array $attributes): Project
    {
        $project = Project::query()->where('organization_id', $proposal->organization_id)
            ->where('public_id', $proposal->target_public_id)->firstOrFail();

        return $this->projectWriter->updateProject($actor, $project, $attributes, (int) $proposal->expected_target_version);
    }

    private function applyDomain(User $actor, AiProposal $proposal, array $attributes): BusinessDomain
    {
        $org = $proposal->commonConversation->organization;
        $domain = BusinessDomain::query()->where('organization_id', $org->id)
            ->where('public_id', $proposal->target_public_id)->where('status', BusinessDomain::STATUS_ACTIVE)->firstOrFail();
        $reason = (string) $attributes['reason'];
        unset($attributes['reason'], $attributes['context_impact_confirmed']);

        return $this->domainWriter->update(
            $actor,
            $org,
            $domain,
            $attributes,
            (int) $proposal->expected_target_version,
            $reason,
            $proposal->common_operation_key,
        );
    }

    private function authorizeOperation(User $actor, AiProposal $proposal, bool $apply): void
    {
        $org = $proposal->commonConversation->organization;
        $this->lineage->authorizeProposal($actor, $proposal);
        $item = $proposal->items->sole();
        $category = match ($item->entity_type) {
            AiCommonProposalContract::CAPTURE_CREATE => OrganizationAiPolicy::CATEGORY_CAPTURE,
            AiCommonProposalContract::ACTION_CREATE, AiCommonProposalContract::ACTION_UPDATE => OrganizationAiPolicy::CATEGORY_ACTION,
            AiCommonProposalContract::PROJECT_UPDATE => OrganizationAiPolicy::CATEGORY_PROJECT,
            AiCommonProposalContract::DOMAIN_UPDATE => OrganizationAiPolicy::CATEGORY_DOMAIN,
            default => throw new AuthorizationException,
        };
        $this->common->authorizeCategory($actor, $org, OrganizationAiPolicy::CATEGORY_COMMON);
        $this->common->authorizeCategory($actor, $org, $category);
        match ($item->entity_type) {
            AiCommonProposalContract::CAPTURE_CREATE => $this->captureAccess->canParticipate($actor, $org) ?: throw new AuthorizationException,
            AiCommonProposalContract::ACTION_CREATE => $this->projectAccess->canCreateAction($actor, Project::query()->where('organization_id', $proposal->organization_id)->where('public_id', $proposal->target_public_id)->firstOrFail()) ?: throw new AuthorizationException,
            AiCommonProposalContract::ACTION_UPDATE => $this->projectAccess->canEditAction($actor, Task::query()->where('organization_id', $proposal->organization_id)->where('public_id', $proposal->target_public_id)->firstOrFail()) ?: throw new AuthorizationException,
            AiCommonProposalContract::PROJECT_UPDATE => $this->projectAccess->canManageStructure($actor, Project::query()->where('organization_id', $proposal->organization_id)->where('public_id', $proposal->target_public_id)->firstOrFail()) ?: throw new AuthorizationException,
            AiCommonProposalContract::DOMAIN_UPDATE => $this->domainAccess->authorizeEdit($actor, $org),
        };
    }

    private function authorizeAppliedResult(User $actor, AiProposal $proposal): void
    {
        $item = $proposal->items->sole();
        match ($item->entity_type) {
            AiCommonProposalContract::CAPTURE_CREATE => $this->captureAccess->canRead($actor, Capture::query()->where('organization_id', $proposal->organization_id)->where('public_id', $item->applied_entity_public_id)->firstOrFail()) ?: throw new AuthorizationException,
            AiCommonProposalContract::ACTION_CREATE, AiCommonProposalContract::ACTION_UPDATE => $this->projectAccess->canRead($actor, Task::query()->where('organization_id', $proposal->organization_id)->where('public_id', $item->applied_entity_public_id)->firstOrFail()->project) ?: throw new AuthorizationException,
            AiCommonProposalContract::PROJECT_UPDATE => $this->projectAccess->canRead($actor, Project::query()->where('organization_id', $proposal->organization_id)->where('public_id', $item->applied_entity_public_id)->firstOrFail()) ?: throw new AuthorizationException,
            AiCommonProposalContract::DOMAIN_UPDATE => $this->domainAccess->authorizeView($actor, $proposal->commonConversation->organization),
        };
    }

    private function assertShape(AiProposal $proposal): void
    {
        if (! AiCommonProposalContract::supports($proposal->contract_version)
            || $proposal->capability !== AiCommonProposalContract::CAPABILITY
            || $proposal->items->count() !== 1
            || $proposal->workspace_id !== null
            || $proposal->project_id !== null) {
            throw ValidationException::withMessages(['proposal' => 'Common Handoff Contractが一致しません。']);
        }
        $item = $proposal->items->sole();
        if (! isset(AiCommonProposalContract::FIELD_MAP[$item->entity_type])
            || array_diff(array_keys($item->after ?? []), AiCommonProposalContract::FIELD_MAP[$item->entity_type]) !== []) {
            throw ValidationException::withMessages(['proposal' => '許可されていないFieldが含まれています。']);
        }
    }

    /** Test seam for proving that Writer side effects and proposal state share one transaction. */
    protected function afterWriterApply(object $target): void
    {
        // Intentionally empty.
    }

    private function newAttempt(AiProposal $proposal, User $actor): AiProposalApplyAttempt
    {
        return DB::transaction(function () use ($proposal, $actor): AiProposalApplyAttempt {
            $locked = AiProposal::query()->lockForUpdate()->findOrFail($proposal->id);
            $attempts = $locked->applyAttempts()->lockForUpdate()->orderBy('attempt_number')->get();
            if ($applied = $attempts->firstWhere('status', AiProposalApplyAttempt::STATUS_APPLIED)) {
                return $applied;
            }
            if ($processing = $attempts->firstWhere('status', AiProposalApplyAttempt::STATUS_PROCESSING)) {
                return $processing;
            }
            if ($locked->status !== AiProposal::STATUS_APPROVED) {
                throw ValidationException::withMessages(['proposal' => '承認済みの提案だけを適用できます。']);
            }
            $number = $attempts->count() + 1;
            if ($number > self::MAX_ATTEMPTS) {
                throw ValidationException::withMessages(['proposal' => '再試行上限に達しました。']);
            }

            return $locked->applyAttempts()->create([
                'actor_id' => $actor->id,
                'attempt_number' => $number,
                'idempotency_key' => 'apply:'.$locked->public_id.':'.$number,
                'status' => AiProposalApplyAttempt::STATUS_PROCESSING,
                'started_at' => now(),
            ]);
        }, 3);
    }
}
