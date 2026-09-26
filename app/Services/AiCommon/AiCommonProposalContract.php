<?php

namespace App\Services\AiCommon;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use Illuminate\Support\Arr;

class AiCommonProposalContract
{
    public const VERSION = 'company-common-handoff.v1';
    public const CAPABILITY = 'company_common_handoff';

    public const CAPTURE_CREATE = 'capture.create';
    public const ACTION_CREATE = 'action.create';
    public const ACTION_UPDATE = 'action.update';
    public const PROJECT_UPDATE = 'project.update';
    public const DOMAIN_UPDATE = 'business_domain.update';

    public const FIELD_MAP = [
        self::CAPTURE_CREATE => ['type', 'body', 'recipient_user_id', 'notification_timing', 'notify_at', 'recipient_confirmed', 'jst_time_confirmed'],
        self::ACTION_CREATE => ['title', 'done_condition', 'assigned_to', 'description', 'reviewer_user_id', 'due_date', 'improvement_id'],
        self::ACTION_UPDATE => ['title', 'description', 'done_condition', 'due_date', 'reason'],
        self::PROJECT_UPDATE => ['purpose', 'expected_outcome'],
        self::DOMAIN_UPDATE => [
            'description', 'what_summary', 'who_summary', 'value_proposition',
            'geographic_scope_summary', 'market_position_summary', 'self_recognized_strengths',
            'reason', 'context_impact_confirmed',
        ],
    ];

    public static function supports(?string $version): bool
    {
        return $version === self::VERSION;
    }

    public static function approval(string $operation): array
    {
        return match ($operation) {
            self::CAPTURE_CREATE => ['L1', 'human_confirm'],
            self::ACTION_CREATE, self::ACTION_UPDATE, self::PROJECT_UPDATE => ['L2', 'human_approve'],
            self::DOMAIN_UPDATE => ['L3', 'human_approve_context_impact'],
            default => ['invalid', 'invalid'],
        };
    }

    public static function canonicalAttributes(string $operation, array $attributes): array
    {
        $allowed = self::FIELD_MAP[$operation] ?? [];
        $value = Arr::only($attributes, $allowed);
        ksort($value);

        return $value;
    }

    public static function proposalHash(AiProposal $proposal): string
    {
        $proposal->loadMissing('items');
        $items = $proposal->items->map(function (AiProposalItem $item): array {
            $after = self::canonicalAttributes($item->entity_type, $item->after ?? $item->attributes ?? []);

            return [
                'public_id' => $item->public_id,
                'operation' => $item->operation,
                'entity_type' => $item->entity_type,
                'target_public_id' => $item->target_public_id,
                'expected_version' => $item->expected_version,
                'before' => $item->before,
                'after' => $after,
            ];
        })->values()->all();
        $canonical = [
            'contract_version' => $proposal->contract_version,
            'capability' => $proposal->capability,
            'risk_level' => $proposal->risk_level,
            'approval_policy' => $proposal->approval_policy,
            'organization_id' => $proposal->organization_id,
            'conversation_id' => $proposal->ai_common_conversation_id,
            'scope_key' => $proposal->scope_key,
            'target_type' => $proposal->target_type,
            'target_public_id' => $proposal->target_public_id,
            'expected_target_version' => $proposal->expected_target_version,
            'requested_by' => $proposal->requested_by,
            'items' => $items,
        ];

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
