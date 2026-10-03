<?php

namespace App\Services\AnnualManagementPolicy;

use App\Models\AnnualManagementPolicy;

class AnnualManagementPolicySnapshot
{
    public const SCHEMA_VERSION = 1;

    public function make(AnnualManagementPolicy $policy): array
    {
        $policy->load([
            'period', 'themes.priorities', 'departments.group', 'departments.statements',
            'grants',
            'relations' => fn ($query) => $query->where('current_status', 'confirmed')->orderBy('id'),
        ]);
        $grantState = $policy->grants->sortBy('organization_user_id')->map(fn ($grant): array => [
            'membership_id' => (int) $grant->organization_user_id,
            'approved' => (bool) $grant->can_view_approved,
            'draft' => (bool) $grant->can_view_draft,
            'edit' => (bool) $grant->can_edit,
            'approve' => (bool) $grant->can_approve,
        ])->values()->all();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'source' => 'human_annual_approval',
            'annual' => [
                'public_id' => $policy->public_id,
                'organization_public_id' => $policy->organization->public_id,
                'period' => [
                    'public_id' => $policy->period->public_id,
                    'organization_version' => (int) $policy->period->version,
                    'organization_name' => $policy->period->name,
                    'organization_starts_on' => $policy->period->starts_on->toDateString(),
                    'organization_ends_on' => $policy->period->ends_on->toDateString(),
                    'declared_name' => $policy->draft_period_name,
                    'declared_starts_on' => $policy->draft_starts_on?->toDateString(),
                    'declared_ends_on' => $policy->draft_ends_on?->toDateString(),
                    'timezone' => 'Asia/Tokyo',
                ],
                'purpose' => $policy->draft_purpose,
                'background' => $policy->draft_background,
                'policy' => $policy->draft_policy,
                'access_binding' => [
                    'approved_view_scope' => $policy->approved_view_scope,
                    'explicit_approved_viewer_count' => collect($grantState)->where('approved', true)->count(),
                    'draft_viewer_count' => collect($grantState)->where('draft', true)->count(),
                    'editor_count' => collect($grantState)->where('edit', true)->count(),
                    'approver_count' => collect($grantState)->where('approve', true)->count(),
                    'grant_state_hash' => hash('sha256', json_encode($grantState, JSON_THROW_ON_ERROR)),
                ],
                'themes' => $policy->themes->map(fn ($theme): array => [
                    'public_id' => $theme->public_id,
                    'statement' => $theme->statement,
                    'explanation' => $theme->explanation,
                    'sort_order' => (int) $theme->sort_order,
                    'priorities' => $theme->priorities->map(fn ($priority): array => [
                        'public_id' => $priority->public_id,
                        'statement' => $priority->statement,
                        'explanation' => $priority->explanation,
                        'sort_order' => (int) $priority->sort_order,
                    ])->values()->all(),
                ])->values()->all(),
                'departments' => $policy->departments->map(fn ($department): array => [
                    'public_id' => $department->public_id,
                    'group_public_id' => $department->group->public_id,
                    'group_name_at_approval' => $department->group->name,
                    'group_archived_at_approval' => $department->group->archived_at !== null,
                    'introduction' => $department->introduction,
                    'sort_order' => (int) $department->sort_order,
                    'statements' => $department->statements->map(fn ($statement): array => [
                        'public_id' => $statement->public_id,
                        'statement' => $statement->statement,
                        'explanation' => $statement->explanation,
                        'sort_order' => (int) $statement->sort_order,
                    ])->values()->all(),
                ])->values()->all(),
                'relation_version' => (int) $policy->relation_version,
                'relation_refs' => $policy->relations->map(fn ($relation): array => [
                    'public_id' => $relation->public_id,
                    'source_type' => $relation->source_type,
                    'source_public_id' => $relation->source_public_id,
                    'target_type' => $relation->target_type,
                    'target_public_id' => $relation->target_public_id,
                    'version' => (int) $relation->current_version,
                ])->values()->all(),
            ],
        ];
    }

    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
