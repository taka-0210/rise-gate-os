<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedSourceContext;
use App\Models\AiCommonSource;
use App\Models\AiCommonSourceRevision;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonSharedContext
{
    public function __construct(
        private readonly AiCommonSharedAccess $access,
        private readonly AiCommonSourceManifest $manifest,
    ) {}

    public function select(User $actor, Organization $organization, AiCommonConversation $conversation, string $type, string $publicId, string $reason): AiCommonSource
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 160) {
            throw ValidationException::withMessages(['selection_reason' => 'Enter a selection reason within 160 characters.']);
        }
        $audience = $this->access->audienceSnapshot($actor, $organization, $conversation);
        $existing = $conversation->sources()->where('resource_type', $type)->where('resource_public_id', $publicId)->first();
        if (! $existing && $conversation->sources()->count() >= AiCommonSourceManifest::MAX_SOURCES) {
            throw ValidationException::withMessages(['sources' => 'Context can contain at most 20 source selections.']);
        }
        $sourceRows = $this->sourceRows($audience, $organization, $conversation, $type, $publicId);
        $canonical = $this->canonicalSnapshot($audience, $sourceRows);
        $actorSnapshot = collect($sourceRows)->firstWhere('user_id', $actor->id)['source'];

        return DB::transaction(function () use ($actor, $conversation, $type, $publicId, $reason, $audience, $canonical, $actorSnapshot, $existing): AiCommonSource {
            $source = $existing
                ? AiCommonSource::query()->lockForUpdate()->findOrFail($existing->id)
                : new AiCommonSource(['ai_common_conversation_id' => $conversation->id, 'resource_type' => $type, 'resource_public_id' => $publicId]);
            $handle = 'src_'.Str::lower(Str::random(48));
            $source->fill([
                'selected_by_user_id' => $actor->id,
                'opaque_handle' => $handle,
                'resource_version' => $actorSnapshot['resource_version'],
                'freshness_fingerprint' => $actorSnapshot['access_fingerprint'],
                'selection_reason' => $reason,
                'projection' => $actorSnapshot['projection'],
                'selected_at' => now(),
            ])->save();
            $revision = $source->revisions()->create([
                'ai_common_conversation_id' => $conversation->id,
                'selected_by_user_id' => $actor->id,
                'opaque_handle' => $handle,
                'resource_type' => $type,
                'resource_public_id' => $publicId,
                'resource_version' => $actorSnapshot['resource_version'],
                'selector' => ['type' => $type, 'public_id' => $publicId],
                'projection' => $actorSnapshot['projection'],
                'organization_policy_version' => $actorSnapshot['organization_policy_version'],
                'resource_policy_version' => $actorSnapshot['resource_policy_version'],
                'membership_access_epoch' => $actorSnapshot['membership_access_epoch'],
                'credential_generation' => $actorSnapshot['credential_generation'],
                'access_fingerprint' => $actorSnapshot['access_fingerprint'],
                'selection_reason' => $reason,
                'selected_at' => now(),
            ]);
            AiCommonSharedSourceContext::query()->create([
                'ai_common_source_revision_id' => $revision->id,
                'ai_common_shared_conversation_id' => $audience['shared']->id,
                'selected_by_participant_id' => $audience['participants']->firstWhere('user_id', $actor->id)->id,
                'participant_version' => $audience['participant_version'],
                'audience_fingerprint' => $canonical['fingerprint'],
                'audience_snapshot' => $canonical['snapshot'],
            ]);
            $source->update(['current_revision_id' => $revision->id]);

            return $source->fresh('currentRevision');
        }, 3);
    }

    public function authorizeRevision(User $actor, Organization $organization, AiCommonSourceRevision $revision): array
    {
        $conversation = $revision->conversation()->firstOrFail();
        $audience = $this->access->audienceSnapshot($actor, $organization, $conversation);
        $context = AiCommonSharedSourceContext::query()->where('ai_common_source_revision_id', $revision->id)->first();
        if (! $context || $context->ai_common_shared_conversation_id !== $audience['shared']->id) {
            throw ValidationException::withMessages(['source' => 'Unknown Shared source lineage is not reusable.']);
        }
        $rows = $this->sourceRows($audience, $organization, $conversation, $revision->resource_type, $revision->resource_public_id);
        $canonical = $this->canonicalSnapshot($audience, $rows);
        $first = $rows[0]['source'];
        if ((int) $context->participant_version !== $audience['participant_version']
            || ! hash_equals($context->audience_fingerprint, $canonical['fingerprint'])
            || $context->audience_snapshot !== $canonical['snapshot']
            || (string) $revision->resource_version !== (string) $first['resource_version']
            || $revision->projection !== $first['projection']) {
            throw ValidationException::withMessages(['source' => 'Shared source audience or revision changed. Reselect it before reuse.']);
        }

        return $this->manifest->sharedProviderSource($revision->opaque_handle, $revision->resource_type, $first);
    }

    public function revisionsForSelections(User $actor, Organization $organization, iterable $sources): EloquentCollection
    {
        $result = new EloquentCollection;
        foreach ($sources as $source) {
            $revision = $source->currentRevision()->first();
            if (! $revision) {
                throw ValidationException::withMessages(['source' => 'Legacy source selections must be reselected before Shared AI reuse.']);
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
            if (count($result) >= AiCommonSourceManifest::MAX_SOURCES) {
                throw ValidationException::withMessages(['sources' => 'Source lineage exceeds the 20 source limit.']);
            }
            $source = $this->authorizeRevision($actor, $organization, $revision);
            $chars += mb_strlen((string) json_encode($source['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            if ($chars > AiCommonSourceManifest::MAX_CONTEXT_CHARS) {
                throw ValidationException::withMessages(['sources' => 'Source lineage exceeds the context limit.']);
            }
            $result[] = $source;
        }

        return $result;
    }

    public function assertSelectionsStillPointTo(iterable $selectionRevisionMap): void
    {
        foreach ($selectionRevisionMap as $sourceId => $revisionId) {
            if ((int) AiCommonSource::query()->whereKey((int) $sourceId)->value('current_revision_id') !== (int) $revisionId) {
                throw ValidationException::withMessages(['source' => 'The selected Shared source changed while the provider was processing.']);
            }
        }
    }

    private function sourceRows(array $audience, Organization $organization, AiCommonConversation $conversation, string $type, string $publicId): array
    {
        $rows = [];
        foreach ($audience['participants'] as $participant) {
            $rows[] = [
                'user_id' => $participant->user_id,
                'participant_id' => $participant->id,
                'source' => $this->manifest->sharedSnapshot($participant->user, $organization, $type, $publicId, $conversation->id),
            ];
        }
        $first = $rows[0]['source'];
        foreach ($rows as $row) {
            $source = $row['source'];
            if ((string) $source['resource_version'] !== (string) $first['resource_version']
                || $source['projection'] !== $first['projection']
                || (int) $source['organization_policy_version'] !== (int) $first['organization_policy_version']
                || (int) $source['resource_policy_version'] !== (int) $first['resource_policy_version']) {
                throw ValidationException::withMessages(['source' => 'The selected source is not identically authorized for every active participant.']);
            }
        }

        return $rows;
    }

    private function canonicalSnapshot(array $audience, array $sourceRows): array
    {
        $rows = collect($sourceRows)->map(fn (array $row): array => [
            'participant_id' => $row['participant_id'],
            'user_id' => $row['user_id'],
            'resource_version' => (string) $row['source']['resource_version'],
            'projection_hash' => hash('sha256', json_encode($row['source']['projection'], JSON_THROW_ON_ERROR)),
            'organization_policy_version' => (int) $row['source']['organization_policy_version'],
            'resource_policy_version' => (int) $row['source']['resource_policy_version'],
            'membership_access_epoch' => (int) $row['source']['membership_access_epoch'],
            'credential_generation' => (int) $row['source']['credential_generation'],
            'access_fingerprint' => $row['source']['access_fingerprint'],
        ])->values()->all();
        $snapshot = ['audience' => $audience['snapshot'], 'sources' => $rows];

        return ['snapshot' => $snapshot, 'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR))];
    }
}
