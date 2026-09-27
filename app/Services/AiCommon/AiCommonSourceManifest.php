<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonAttachmentDerivative;
use App\Models\AiCommonConversation;
use App\Models\AiCommonSource;
use App\Models\AiCommonSourceRevision;
use App\Models\AiCommonTranscriptRevision;
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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        private readonly AiCommonAttachmentAccess $attachments,
    ) {}

    public function select(User $actor, Organization $organization, AiCommonConversation $conversation, string $type, string $publicId, string $reason): AiCommonSource
    {
        $this->common->authorizeConversation($actor, $organization, $conversation, true);
        $existing = $conversation->sources()->where('resource_type', $type)->where('resource_public_id', $publicId)->first();
        if (! $existing && $conversation->sources()->count() >= self::MAX_SOURCES) {
            throw ValidationException::withMessages(['sources' => 'Context can contain at most 20 source selections.']);
        }
        if (trim($reason) === '' || mb_strlen($reason) > 160) {
            throw ValidationException::withMessages(['selection_reason' => 'Enter a selection reason within 160 characters.']);
        }

        $snapshot = $this->snapshot($actor, $organization, $type, $publicId, $conversation->id);

        return DB::transaction(function () use ($actor, $conversation, $type, $publicId, $reason, $snapshot, $existing): AiCommonSource {
            $source = $existing
                ? AiCommonSource::query()->lockForUpdate()->findOrFail($existing->id)
                : new AiCommonSource([
                    'ai_common_conversation_id' => $conversation->id,
                    'resource_type' => $type,
                    'resource_public_id' => $publicId,
                ]);
            $handle = 'src_'.Str::lower(Str::random(48));
            $source->fill([
                'selected_by_user_id' => $actor->id,
                'opaque_handle' => $handle,
                'resource_version' => $snapshot['resource_version'],
                'freshness_fingerprint' => $snapshot['access_fingerprint'],
                'selection_reason' => trim($reason),
                'projection' => $snapshot['projection'],
                'selected_at' => now(),
            ])->save();
            $revision = $source->revisions()->create([
                'ai_common_conversation_id' => $conversation->id,
                'selected_by_user_id' => $actor->id,
                'opaque_handle' => $handle,
                'resource_type' => $type,
                'resource_public_id' => $publicId,
                'resource_version' => $snapshot['resource_version'],
                'selector' => ['type' => $type, 'public_id' => $publicId],
                'projection' => $snapshot['projection'],
                'organization_policy_version' => $snapshot['organization_policy_version'],
                'resource_policy_version' => $snapshot['resource_policy_version'],
                'membership_access_epoch' => $snapshot['membership_access_epoch'],
                'credential_generation' => $snapshot['credential_generation'],
                'access_fingerprint' => $snapshot['access_fingerprint'],
                'selection_reason' => trim($reason),
                'selected_at' => now(),
            ]);
            $source->update(['current_revision_id' => $revision->id]);

            return $source->fresh('currentRevision');
        }, 3);
    }

    /** Backward-compatible selection authorization. New callers should retain the immutable revision. */
    public function authorize(User $actor, Organization $organization, AiCommonSource $source): array
    {
        $revision = $source->currentRevision()->first();
        if ($revision) {
            return $this->authorizeRevision($actor, $organization, $revision);
        }

        if ($source->conversation->organization_id !== $organization->id
            || $source->conversation->user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $snapshot = $this->snapshot($actor, $organization, $source->resource_type, $source->resource_public_id, $source->ai_common_conversation_id);
        if (! hash_equals($source->freshness_fingerprint, $snapshot['access_fingerprint'])) {
            throw ValidationException::withMessages(['source' => 'The source changed. Reselect it before reuse.']);
        }

        return $this->providerSource($source->opaque_handle, $source->resource_type, $snapshot);
    }

    public function authorizeRevision(User $actor, Organization $organization, AiCommonSourceRevision $revision): array
    {
        if ($revision->conversation->organization_id !== $organization->id
            || $revision->conversation->user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        $snapshot = $this->snapshot($actor, $organization, $revision->resource_type, $revision->resource_public_id, $revision->ai_common_conversation_id);
        if (! hash_equals($revision->access_fingerprint, $snapshot['access_fingerprint'])
            || $revision->resource_version !== $snapshot['resource_version']
            || $revision->projection !== $snapshot['projection']) {
            throw ValidationException::withMessages(['source' => 'The source revision is no longer current. Reselect it before reuse.']);
        }

        return $this->providerSource($revision->opaque_handle, $revision->resource_type, $snapshot);
    }

    public function revisionsForSelections(User $actor, Organization $organization, iterable $sources): EloquentCollection
    {
        $result = new EloquentCollection;
        foreach ($sources as $source) {
            $revision = $source->currentRevision()->first();
            if (! $revision) {
                throw ValidationException::withMessages(['source' => 'Legacy source selections must be reselected before AI reuse.']);
            }
            $this->authorizeRevision($actor, $organization, $revision);
            $result->push($revision);
        }

        return $result;
    }

    public function authorizedRevisions(User $actor, Organization $organization, iterable $revisions): array
    {
        $result = [];
        $chars = 0;
        foreach (collect($revisions)->unique('id')->values() as $revision) {
            if (count($result) >= self::MAX_SOURCES) {
                throw ValidationException::withMessages(['sources' => 'Source lineage exceeds the 20 source limit.']);
            }
            $authorized = $this->authorizeRevision($actor, $organization, $revision);
            $encoded = json_encode($authorized['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $chars += mb_strlen((string) $encoded);
            if ($chars > self::MAX_CONTEXT_CHARS) {
                throw ValidationException::withMessages(['sources' => 'Source lineage exceeds the context limit.']);
            }
            $result[] = $authorized;
        }

        return $result;
    }

    public function authorizedMany(User $actor, Organization $organization, iterable $sources): array
    {
        return $this->authorizedRevisions(
            $actor,
            $organization,
            $this->revisionsForSelections($actor, $organization, $sources),
        );
    }

    public function assertSelectionsStillPointTo(iterable $selectionRevisionMap): void
    {
        foreach ($selectionRevisionMap as $sourceId => $revisionId) {
            $current = AiCommonSource::query()->whereKey((int) $sourceId)->value('current_revision_id');
            if ((int) $current !== (int) $revisionId) {
                throw ValidationException::withMessages(['source' => 'The selected source changed while the provider was processing.']);
            }
        }
    }

    /** Internal Shared-Conversation seam. The same resolver/policy projection is
     * deliberately reused so Shared cannot become a second context engine. */
    public function sharedSnapshot(
        User $actor,
        Organization $organization,
        string $type,
        string $publicId,
        int $conversationId,
    ): array {
        return $this->snapshot($actor, $organization, $type, $publicId, $conversationId);
    }

    public function sharedProviderSource(string $handle, string $type, array $snapshot): array
    {
        return $this->providerSource($handle, $type, $snapshot);
    }

    private function snapshot(User $actor, Organization $organization, string $type, string $publicId, ?int $conversationId = null): array
    {
        $resolved = $this->resolve($actor, $organization, $type, $publicId, $conversationId);
        [, $category, $version, $projection] = $resolved;
        [$organizationPolicyVersion, $resourcePolicyVersion] = $this->authorizePolicy(
            $actor,
            $organization,
            $category,
            $type,
            $publicId,
            $resolved[4] ?? null,
        );
        $projection = $this->limitProjection($projection);
        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->firstOrFail();
        $state = [
            'resource_version' => (string) $version,
            'projection' => $projection,
            'organization_policy_version' => $organizationPolicyVersion,
            'resource_policy_version' => $resourcePolicyVersion,
            'membership_access_epoch' => (int) $membership->access_epoch,
            'credential_generation' => (int) $actor->fresh()->credential_generation,
        ];
        $state['access_fingerprint'] = $this->fingerprint($type, $publicId, $state);

        return $state;
    }

    private function providerSource(string $handle, string $type, array $snapshot): array
    {
        return [
            'handle' => $handle,
            'type' => $type,
            'version' => $snapshot['resource_version'],
            'data' => $snapshot['projection'],
        ];
    }

    private function resolve(User $actor, Organization $organization, string $type, string $publicId, ?int $conversationId): array
    {
        return match ($type) {
            'project' => $this->project($actor, $organization, $publicId),
            'action' => $this->action($actor, $organization, $publicId),
            'business_domain' => $this->domain($actor, $organization, $publicId),
            'capture' => $this->capture($actor, $organization, $publicId),
            'attachment_extract' => $this->attachmentExtract($actor, $organization, $publicId, $conversationId),
            'attachment_transcript' => $this->attachmentTranscript($actor, $organization, $publicId, $conversationId),
            default => throw ValidationException::withMessages(['resource_type' => 'Unsupported AI source type.']),
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

    private function attachmentExtract(User $actor, Organization $organization, string $publicId, ?int $conversationId): array
    {
        $derivative = AiCommonAttachmentDerivative::query()->where('public_id', $publicId)
            ->where('state', AiCommonAttachmentDerivative::STATE_READY)->firstOrFail();
        $attachment = $derivative->attachment()->firstOrFail();
        $conversation = $attachment->conversation()->firstOrFail();
        if ($conversationId !== null && $conversation->id !== $conversationId) {
            throw new AuthorizationException;
        }
        $this->attachments->authorizeAttachment($actor, $organization, $conversation, $attachment, true);
        if ($attachment->uploaded_by_user_id !== $actor->id || ! $attachment->allows_ai_reference) {
            throw new AuthorizationException;
        }

        return [$derivative, OrganizationAiPolicy::CATEGORY_ATTACHMENT,
            hash('sha256', $derivative->content_sha256.'|'.$derivative->attachment_version),
            [
                'derivative_id' => $derivative->public_id,
                'kind' => $derivative->kind,
                'selector' => $derivative->selector,
                'content' => $derivative->content,
                'content_sha256' => $derivative->content_sha256,
            ],
            (int) $attachment->ai_reference_version,
        ];
    }

    private function attachmentTranscript(User $actor, Organization $organization, string $publicId, ?int $conversationId): array
    {
        $revision = AiCommonTranscriptRevision::query()->where('public_id', $publicId)->firstOrFail();
        $attachment = $revision->attachment()->firstOrFail();
        $conversation = $attachment->conversation()->firstOrFail();
        if ($conversationId !== null && $conversation->id !== $conversationId) {
            throw new AuthorizationException;
        }
        $this->attachments->authorizeAttachment($actor, $organization, $conversation, $attachment, true);
        if ($attachment->uploaded_by_user_id !== $actor->id || ! $attachment->allows_ai_reference) {
            throw new AuthorizationException;
        }

        return [$revision, OrganizationAiPolicy::CATEGORY_ATTACHMENT,
            hash('sha256', $revision->content_sha256.'|'.$revision->audio_sha256),
            [
                'transcript_revision_id' => $revision->public_id,
                'revision_number' => $revision->revision_number,
                'range_start_ms' => $revision->range_start_ms,
                'range_end_ms' => $revision->range_end_ms,
                'range_precision' => $revision->range_precision,
                'content' => $revision->content,
                'content_sha256' => $revision->content_sha256,
            ],
            (int) $attachment->ai_reference_version,
        ];
    }

    private function authorizePolicy(User $actor, Organization $organization, string $category, string $type, string $publicId, ?int $embeddedPolicyVersion = null): array
    {
        $organizationPolicy = $this->common->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $this->common->authorizeCategory($actor, $organization, $category);
        if ($embeddedPolicyVersion !== null) {
            return [(int) $organizationPolicy->version, $embeddedPolicyVersion];
        }
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
            throw ValidationException::withMessages(['source' => 'The selected source exceeds the source context limit.']);
        }

        return $projection;
    }

    private function fingerprint(string $type, string $publicId, array $state): string
    {
        $projection = $state['projection'];
        ksort($projection);

        return hash('sha256', json_encode([
            $type,
            $publicId,
            $state['resource_version'],
            $projection,
            'membership_access_epoch' => $state['membership_access_epoch'],
            'credential_generation' => $state['credential_generation'],
            'organization_policy_version' => $state['organization_policy_version'],
            'resource_policy_version' => $state['resource_policy_version'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
