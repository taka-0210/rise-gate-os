<?php

namespace App\Services\AiCommon;

use App\Models\AiResourcePolicy;
use App\Models\BusinessDomain;
use App\Models\Capture;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\Capture\CaptureAccess;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiCommonResourcePolicyWriter
{
    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly ProjectExecutionAccess $projects,
        private readonly BusinessDomainAccess $domains,
        private readonly CaptureAccess $captures,
        private readonly AiCommonManagementContext $management,
    ) {}

    public function update(User $actor, Organization $organization, string $type, string $publicId, bool $allows): AiResourcePolicy
    {
        $this->common->authorizePolicyManager($actor, $organization);
        $this->authorizeResource($actor, $organization, $type, $publicId);

        return DB::transaction(function () use ($actor, $organization, $type, $publicId, $allows): AiResourcePolicy {
            $policy = AiResourcePolicy::query()->lockForUpdate()->firstOrNew([
                'organization_id' => $organization->id,
                'resource_type' => $type,
                'resource_public_id' => $publicId,
            ]);
            $policy->fill([
                'allows_ai_reference' => $allows,
                'version' => $policy->exists ? $policy->version + 1 : 1,
                'managed_by_user_id' => $actor->id,
            ])->save();

            return $policy->fresh();
        }, 3);
    }

    private function authorizeResource(User $actor, Organization $organization, string $type, string $publicId): void
    {
        if (in_array($type, ['management_design', 'annual_management_policy'], true)) {
            $this->management->resolve($actor, $organization, $type, $publicId);

            return;
        }
        $allowed = match ($type) {
            'project' => $this->projects->canManageStructure($actor, Project::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail()),
            'action' => $this->projects->canEditAction($actor, Task::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail()),
            'business_domain' => $this->domains->canEdit($actor, $organization)
                && BusinessDomain::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->where('status', BusinessDomain::STATUS_ACTIVE)->exists(),
            'capture' => $this->captures->canRead($actor, Capture::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail()),
            default => throw ValidationException::withMessages(['resource_type' => '対象種別が不正です。']),
        };
        if (! $allowed) {
            throw new AuthorizationException;
        }
    }
}
