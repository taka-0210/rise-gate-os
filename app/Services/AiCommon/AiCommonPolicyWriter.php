<?php

namespace App\Services\AiCommon;

use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiCommonPolicyWriter
{
    private const CATEGORIES = [
        OrganizationAiPolicy::CATEGORY_COMMON,
        OrganizationAiPolicy::CATEGORY_PROJECT,
        OrganizationAiPolicy::CATEGORY_ACTION,
        OrganizationAiPolicy::CATEGORY_DOMAIN,
        OrganizationAiPolicy::CATEGORY_CAPTURE,
        OrganizationAiPolicy::CATEGORY_ATTACHMENT,
    ];

    public function __construct(private readonly AiCommonAccess $access) {}

    public function update(User $actor, Organization $organization, array $input, ?int $expectedVersion): OrganizationAiPolicy
    {
        return DB::transaction(function () use ($actor, $organization, $input, $expectedVersion): OrganizationAiPolicy {
            $this->access->authorizePolicyManager($actor, $organization);
            $policy = OrganizationAiPolicy::query()->lockForUpdate()->firstOrNew(['organization_id' => $organization->id]);
            if ($policy->exists && $expectedVersion !== null && $policy->version !== $expectedVersion) {
                throw ValidationException::withMessages(['policy' => 'AI Policyが更新されています。再読込してください。']);
            }
            $categories = array_values(array_unique(array_intersect(
                self::CATEGORIES,
                array_map('strval', Arr::wrap($input['allowed_categories'] ?? [])),
            )));
            $policy->fill([
                'is_enabled' => filter_var($input['is_enabled'] ?? false, FILTER_VALIDATE_BOOL),
                'allows_transcription' => filter_var($input['allows_transcription'] ?? false, FILTER_VALIDATE_BOOL),
                'allowed_categories' => $categories,
                'version' => $policy->exists ? $policy->version + 1 : 1,
                'managed_by_user_id' => $actor->id,
                'confirmed_at' => now(),
            ])->save();

            return $policy->fresh();
        }, 3);
    }
}
