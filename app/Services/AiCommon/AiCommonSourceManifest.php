<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSource;
use App\Models\AiResourcePolicy;
use App\Models\BusinessDomain;
use App\Models\Capture;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\AiProjectContextGuard;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\Capture\CaptureAccess;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonSourceManifest
{
    public const MAX_SOURCES = 20;
    public const MAX_SOURCE_CHARS = 2000;
    public const MAX_CONTEXT_CHARS = 20000;

    private const DOMAIN_FIELDS = [
        'description', 'what_summary', 'who_summary', 'value_proposition',
        'geographic_scope_summary', 'market_position_summary', 'self_recognized_strengths',
    ];

    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly AiProjectContextGuard $projectGuard,
        private readonly ProjectExecutionAccess $projects,
        private readonly BusinessDomainAccess $domains,
        private readonly CaptureAccess $captures,
    ) {}

    public function select(User $actor, Organization $organization, AiCommonConversation $conversation, string $type, string $publicId, string $reason): AiCommonSource
    {
        $this->common->authorizeConversation($actor, $organization, $conversation);
        if ($conversation->sources()->count() >= self::MAX_SOURCES) {
            throw ValidationException::withMessages(['sources' => 'Contextは20件まで選択できます。']);
        }
        if (trim($reason) === '' || mb_strlen($reason) > 160) {
            throw ValidationException::withMessages(['selection_reason' => '選択理由を160文字以内で入力してください。']);
        }
        [$resource, $category, $version, $projection] = $this->resolve($actor, $organization, $type, $publicId);
        [$organizationPolicyVersion, $resourcePolicyVersion] = $this->authorizePolicy($actor, $organization, $category, $type, $publicId);
        $projection = $this->limitProjection($projection);
        $fingerprint = $this->fingerprint($actor, $organization, $type, $publicId, $version, $projection, $organizationPolicyVersion, $resourcePolicyVersion);
        $source = AiCommonSource::query()->updateOrCreate(
            [
                'ai_common_conversation_id' => $conversation->id,
                'resource_type' => $type,
                'resource_public_id' => $publicId,
            ],
            [
                'selected_by_user_id' => $actor->id,
                'opaque_handle' => 'src_'.Str::lower(Str::random(48)),
                'resource_version' => (string) $version,
                'freshness_fingerprint' => $fingerprint,
                'selection_reason' => trim($reason),
                'projection' => $projection,
                'selected_at' => now(),
            ],
        );

        return $source->fresh();
    }

    public function authorize(User $actor, Organization $organization, AiCommonSource $source): array
    {
        if ($source->conversation->organization_id !== $organization->id
            || $source->conversation->user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        [, $category, $version, $projection] = $this->resolve(
            $actor,
            $organization,
            $source->resource_type,
            $source->resource_public_id,
        );
        [$organizationPolicyVersion, $resourcePolicyVersion] = $this->authorizePolicy(
            $actor,
            $organization,
            $category,
            $source->resource_type,
            $source->resource_public_id,
        );
        $projection = $this->limitProjection($projection);
        $fingerprint = $this->fingerprint(
            $actor,
            $organization,
            $source->resource_type,
            $source->resource_public_id,
            $version,
            $projection,
            $organizationPolicyVersion,
            $resourcePolicyVersion,
        );
        if (! hash_equals($source->freshness_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['source' => '参照元が更新されています。Contextを選び直してください。']);
        }

        return [
            'handle' => $source->opaque_handle,
            'type' => $source->resource_type,
            'version' => (string) $version,
            'data' => $projection,
        ];
    }

    public function authorizedMany(User $actor, Organization $organization, iterable $sources): array
    {
        $result = [];
        $chars = 0;
        foreach ($sources as $source) {
            if (count($result) >= self::MAX_SOURCES) {
                break;
            }
            $authorized = $this->authorize($actor, $organization, $source);
            $encoded = json_encode($authorized['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $chars += mb_strlen((string) $encoded);
            if ($chars > self::MAX_CONTEXT_CHARS) {
                throw ValidationException::withMessages(['sources' => 'Context上限を超えました。対象を減らしてください。']);
            }
            $result[] = $authorized;
        }

        return $result;
    }

    private function resolve(User $actor, Organization $organization, string $type, string $publicId): array
    {
        return match ($type) {
            'project' => $this->project($actor, $organization, $publicId),
            'action' => $this->action($actor, $organization, $publicId),
            'business_domain' => $this->domain($actor, $organization, $publicId),
            'capture' => $this->capture($actor, $organization, $publicId),
            default => throw ValidationException::withMessages(['resource_type' => 'AI参照対象が不正です。']),
        };
    }

    private function project(User $actor, Organization $organization, string $publicId): array
    {
        $project = Project::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
        $categories = $this->projectGuard->allowedCategoriesForUser($actor, $project);
        if (! in_array('project_metadata', $categories, true) || ! $this->projects->canRead($actor, $project)) {
            throw new AuthorizationException;
        }

        return [$project, OrganizationAiPolicy::CATEGORY_PROJECT, $project->plan_version, Arr::only($project->toArray(), [
            'public_id', 'name', 'purpose', 'expected_outcome', 'status', 'start_date', 'due_date',
        ])];
    }

    private function action(User $actor, Organization $organization, string $publicId): array
    {
        $action = Task::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
        $this->projectGuard->allowedCategoriesForUser($actor, $action->project);
        if (! $this->projects->canRead($actor, $action->project)) {
            throw new AuthorizationException;
        }

        return [$action, OrganizationAiPolicy::CATEGORY_ACTION, $action->plan_version, Arr::only($action->toArray(), [
            'public_id', 'title', 'description', 'done_condition', 'status', 'due_date',
        ])];
    }

    private function domain(User $actor, Organization $organization, string $publicId): array
    {
        $this->domains->authorizeView($actor, $organization);
        $domain = BusinessDomain::query()->where('organization_id', $organization->id)
            ->where('public_id', $publicId)->where('status', BusinessDomain::STATUS_ACTIVE)->firstOrFail();

        return [$domain, OrganizationAiPolicy::CATEGORY_DOMAIN, $domain->version,
            ['source' => 'organization_self_reported', 'public_id' => $domain->public_id]
            + Arr::only($domain->toArray(), self::DOMAIN_FIELDS)];
    }

    private function capture(User $actor, Organization $organization, string $publicId): array
    {
        $capture = Capture::query()->where('organization_id', $organization->id)->where('public_id', $publicId)->firstOrFail();
        if (! $this->captures->canRead($actor, $capture)) {
            throw new AuthorizationException;
        }

        return [$capture, OrganizationAiPolicy::CATEGORY_CAPTURE, $capture->version, Arr::only($capture->toArray(), [
            'public_id', 'type', 'body', 'status', 'notification_timing', 'notify_at_utc',
        ])];
    }

    private function authorizePolicy(User $actor, Organization $organization, string $category, string $type, string $publicId): array
    {
        $organizationPolicy = $this->common->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $this->common->authorizeCategory($actor, $organization, $category);
        $resource = AiResourcePolicy::query()->where([
            'organization_id' => $organization->id,
            'resource_type' => $type,
            'resource_public_id' => $publicId,
            'allows_ai_reference' => true,
        ])->first();
        if (! $resource) {
            throw new AuthorizationException;
        }

        return [(int) $organizationPolicy->version, (int) $resource->version];
    }

    private function limitProjection(array $projection): array
    {
        $encoded = json_encode($projection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (mb_strlen((string) $encoded) > self::MAX_SOURCE_CHARS) {
            throw ValidationException::withMessages(['source' => '選択したContextが大きすぎます。']);
        }

        return $projection;
    }

    private function fingerprint(
        User $actor,
        Organization $organization,
        string $type,
        string $publicId,
        int|string $version,
        array $projection,
        int $organizationPolicyVersion,
        int $resourcePolicyVersion,
    ): string
    {
        ksort($projection);
        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->firstOrFail();

        return hash('sha256', json_encode([
            $type,
            $publicId,
            (string) $version,
            $projection,
            'membership_access_epoch' => (int) $membership->access_epoch,
            'credential_generation' => (int) $actor->fresh()->credential_generation,
            'organization_policy_version' => $organizationPolicyVersion,
            'resource_policy_version' => $resourcePolicyVersion,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
