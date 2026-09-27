<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonHandoffRelation;
use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\BusinessDomain;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonProposalFactory
{
    public function __construct(private readonly AiCommonAccess $access) {}

    public function create(User $actor, Organization $organization, AiCommonConversation $conversation, array $input): AiProposal
    {
        $this->access->authorizeConversation($actor, $organization, $conversation, true);
        $operation = (string) ($input['operation'] ?? '');
        if (! array_key_exists($operation, AiCommonProposalContract::FIELD_MAP)) {
            throw ValidationException::withMessages(['operation' => 'この操作はAI Handoff対象外です。']);
        }
        $rawAttributes = array_filter(
            $input['attributes'] ?? [],
            fn ($value): bool => $value !== null && $value !== '',
        );
        $smuggled = array_diff(array_keys($rawAttributes), AiCommonProposalContract::FIELD_MAP[$operation]);
        if ($smuggled !== []) {
            throw ValidationException::withMessages(['attributes' => '許可されていないFieldが含まれています。']);
        }
        $attributes = AiCommonProposalContract::canonicalAttributes($operation, $rawAttributes);
        [$targetType, $targetId, $targetVersion, $itemVersion, $before] = $this->target($organization, $operation, $input);
        $this->validateInput($operation, $attributes, $targetId);
        [$risk, $policy] = AiCommonProposalContract::approval($operation);
        $idempotency = trim((string) ($input['idempotency_key'] ?? ''));
        if ($idempotency === '' || mb_strlen($idempotency) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => '操作IDを指定してください。']);
        }
        $commonOperationKey = AiCommonProposalContract::operationUuid(implode('|', [
            $organization->id, $actor->id, $conversation->id, $operation, $idempotency,
        ]));

        return DB::transaction(function () use ($actor, $organization, $conversation, $input, $operation, $attributes, $targetType, $targetId, $targetVersion, $itemVersion, $before, $risk, $policy, $idempotency, $commonOperationKey): AiProposal {
            $existing = AiProposal::query()->where('common_operation_key', $commonOperationKey)->first();
            if ($existing) {
                $expected = AiCommonProposalContract::canonicalAttributes($operation, $existing->items()->firstOrFail()->after ?? []);
                if ($existing->contract_version !== AiCommonProposalContract::VERSION || $expected !== $attributes) {
                    throw ValidationException::withMessages(['idempotency_key' => '同じ操作IDを異なる内容には使用できません。']);
                }

                return $existing->load('items');
            }
            $proposal = AiProposal::query()->create([
                'organization_id' => $organization->id,
                'workspace_id' => null,
                'project_id' => null,
                'ai_common_conversation_id' => $conversation->id,
                'scope_key' => 'organization:'.$organization->public_id.':user:'.$actor->id,
                'target_type' => $targetType,
                'target_public_id' => $targetId,
                'expected_target_version' => $targetVersion,
                'source' => 'ai_common_entry',
                'mode' => AiProposal::MODE_DIFFERENTIAL,
                'idempotency_key' => $idempotency,
                'common_operation_key' => $commonOperationKey,
                'title' => trim((string) ($input['title'] ?? 'COからの変更提案')),
                'summary' => trim((string) ($input['summary'] ?? '')) ?: null,
                'status' => AiProposal::STATUS_PENDING,
                'evidence' => ['private_conversation_public_id' => $conversation->public_id],
                'requested_by' => $actor->id,
                'contract_version' => AiCommonProposalContract::VERSION,
                'capability' => AiCommonProposalContract::CAPABILITY,
                'risk_level' => $risk,
                'approval_policy' => $policy,
            ]);
            $item = $proposal->items()->create([
                'operation' => str_ends_with($operation, '.create') ? AiProposalItem::OPERATION_CREATE : AiProposalItem::OPERATION_UPDATE,
                'entity_type' => $operation,
                'target_public_id' => $targetId,
                'attributes' => $attributes,
                'before' => $before,
                'after' => $attributes,
                'expected_version' => $itemVersion,
                'sort_order' => 1,
                'validation_status' => 'valid',
            ]);
            $proposal->setRelation('items', collect([$item]));
            $proposal->update(['content_hash' => AiCommonProposalContract::proposalHash($proposal)]);
            AiCommonHandoffRelation::query()->create([
                'ai_common_conversation_id' => $conversation->id,
                'ai_proposal_id' => $proposal->id,
                'published_summary' => trim((string) ($input['published_summary'] ?? '')) ?: null,
            ]);

            return $proposal->fresh('items');
        }, 3);
    }

    private function target(Organization $organization, string $operation, array $input): array
    {
        $publicId = filled($input['target_public_id'] ?? null) ? (string) $input['target_public_id'] : null;

        return match ($operation) {
            AiCommonProposalContract::CAPTURE_CREATE => ['capture', null, null, null, null],
            AiCommonProposalContract::ACTION_CREATE => (function () use ($organization, $publicId): array {
                $project = Project::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
                return ['project', $project->public_id, $project->plan_version, $project->plan_version, null];
            })(),
            AiCommonProposalContract::ACTION_UPDATE => (function () use ($organization, $publicId): array {
                $action = Task::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
                return ['action', $action->public_id, $action->project->plan_version, $action->plan_version,
                    $action->only(['title', 'description', 'done_condition', 'due_date'])];
            })(),
            AiCommonProposalContract::PROJECT_UPDATE => (function () use ($organization, $publicId): array {
                $project = Project::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
                return ['project', $project->public_id, $project->plan_version, $project->plan_version,
                    $project->only(['purpose', 'expected_outcome'])];
            })(),
            AiCommonProposalContract::DOMAIN_UPDATE => (function () use ($organization, $publicId): array {
                $domain = BusinessDomain::query()->where('organization_id', $organization->id)->where('public_id', $publicId)
                    ->where('status', BusinessDomain::STATUS_ACTIVE)->firstOrFail();
                return ['business_domain', $domain->public_id, $domain->version, $domain->version,
                    $domain->only(AiCommonProposalContract::FIELD_MAP[AiCommonProposalContract::DOMAIN_UPDATE])];
            })(),
        };
    }

    private function validateInput(string $operation, array $attributes, ?string $targetId): void
    {
        if ($operation !== AiCommonProposalContract::CAPTURE_CREATE && ! $targetId) {
            throw ValidationException::withMessages(['target_public_id' => '対象を指定してください。']);
        }
        if ($operation === AiCommonProposalContract::CAPTURE_CREATE) {
            foreach (['type', 'body', 'recipient_user_id', 'notification_timing'] as $key) {
                if (blank($attributes[$key] ?? null)) {
                    throw ValidationException::withMessages([$key => 'Captureの確定値を入力してください。']);
                }
            }
            if (! filter_var($attributes['recipient_confirmed'] ?? false, FILTER_VALIDATE_BOOL)
                || (($attributes['notification_timing'] ?? null) === 'specified'
                    && ! filter_var($attributes['jst_time_confirmed'] ?? false, FILTER_VALIDATE_BOOL))) {
                throw ValidationException::withMessages(['confirmation' => '宛先とJST具体時刻を人が確認してください。']);
            }
        }
        if ($operation === AiCommonProposalContract::ACTION_CREATE) {
            foreach (['title', 'done_condition', 'assigned_to'] as $key) {
                if (blank($attributes[$key] ?? null)) {
                    throw ValidationException::withMessages([$key => 'Actionの必須項目です。']);
                }
            }
        }
        if ($operation === AiCommonProposalContract::DOMAIN_UPDATE
            && (! filled($attributes['reason'] ?? null)
                || ! filter_var($attributes['context_impact_confirmed'] ?? false, FILTER_VALIDATE_BOOL))) {
            throw ValidationException::withMessages(['confirmation' => '変更理由と今後のAI Contextへの影響を確認してください。']);
        }
    }
}
