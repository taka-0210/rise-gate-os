<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedCheckpointDependency;
use App\Models\AiCommonSharedContextCheckpoint;
use App\Models\AiCommonSharedCoState;
use App\Models\AiCommonSharedDeviceCursor;
use App\Models\AiCommonSharedIdentityRevision;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\AiCommonSharedSessionEndCandidate;
use App\Models\AiCommonSharedSessionEndRun;
use App\Models\AiCommonSharedTranscriptChunk;
use App\Models\AiCommonSharedTranscriptRevision;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiCommonSharedLongContext
{
    public const PURPOSE_MAINTENANCE = 'context_maintenance';

    public const PURPOSE_CO = 'shared_co_request';

    private const MAX_DELTA_CHARACTERS = 12_000;

    private const MAX_CHUNK_CHARACTERS = 2_000;

    private const MAX_RECENT_CHARACTERS = 4_000;

    private const MAX_HISTORICAL_CHARACTERS = 4_000;

    private const MAX_HISTORICAL_CHUNKS = 5;

    private const MAX_INPUT_TOKENS = 12_000;

    public function __construct(
        private readonly AiCommonSharedSessionAccess $sessionAccess,
        private readonly AiCommonSharedAccess $sharedAccess,
        private readonly AiCommonAccess $commonAccess,
        private readonly AiCommonGateway $gateway,
    ) {}

    public function markDirty(AiCommonSharedTranscriptRevision $revision): void
    {
        $segment = $revision->segment()->firstOrFail();
        DB::transaction(function () use ($segment): void {
            $session = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($segment->ai_common_shared_session_id);
            $identityId = $segment->identityRevisions()->orderByDesc('revision_no')->value('id');
            if ($session->context_dirty_from_segment_id === null && $session->context_current_checkpoint_id
                && AiCommonSharedCheckpointDependency::query()
                    ->where('ai_common_shared_context_checkpoint_id', $session->context_current_checkpoint_id)
                    ->where('transcript_segment_id', $segment->id)
                    ->where('transcript_revision_id', $segment->current_revision_id)
                    ->where('identity_revision_id', $identityId)
                    ->exists()) {
                return;
            }
            $dirtyFrom = $session->context_dirty_from_segment_id === null
                ? $segment->id
                : min($session->context_dirty_from_segment_id, $segment->id);
            $session->update([
                'context_dirty_from_segment_id' => $dirtyFrom,
                'context_status' => 'lagging',
                'room_sequence' => $session->room_sequence + 1,
            ]);
            AiCommonSharedTranscriptChunk::query()
                ->where('ai_common_shared_session_id', $session->id)
                ->where('first_segment_id', '<=', $segment->id)
                ->where('last_segment_id', '>=', $segment->id)
                ->where('status', 'current')
                ->update(['status' => 'stale']);
            AiCommonSharedCoState::query()
                ->where('current_session_id', $session->id)
                ->update(['context_state' => 'lagging', 'room_sequence' => $session->room_sequence]);
        }, 3);
    }

    public function maintain(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        string $operationId,
    ): ?AiCommonSharedContextCheckpoint {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid Context Maintenance operation is required.']);
        }
        $this->authorizeContext($actor, $organization, $conversation, $session);
        $session = $session->fresh();
        $current = $this->currentCheckpoint($session);
        if ($session->context_dirty_from_segment_id === null && $current) {
            return $this->authorizeCheckpoint($actor, $organization, $conversation, $session, $current);
        }

        $rows = $this->currentRows($session);
        if ($rows->isEmpty()) {
            return null;
        }
        $dirtyFrom = $session->context_dirty_from_segment_id ?? 1;
        $delta = $rows->filter(fn (array $row): bool => $row['segment']->id >= $dirtyFrom)->values();
        if ($delta->isEmpty() && $current) {
            return $this->authorizeCheckpoint($actor, $organization, $conversation, $session, $current);
        }
        $audience = $this->sharedAccess->audienceSnapshot($actor, $organization, $conversation);
        $revisionIds = $rows->pluck('revision.id')->map(fn ($id): int => (int) $id)->all();
        $payloadFingerprint = hash('sha256', json_encode([
            'session' => $session->id,
            'previous' => $current?->id,
            'revisions' => $revisionIds,
            'purpose' => $session->purpose_revision_id,
            'audience' => $audience['fingerprint'],
        ], JSON_THROW_ON_ERROR));
        $existing = AiCommonSharedContextCheckpoint::query()
            ->where('ai_common_shared_session_id', $session->id)
            ->where('operation_id', $operationId)
            ->first();
        if ($existing) {
            if (! hash_equals($existing->payload_fingerprint, $payloadFingerprint)) {
                throw ValidationException::withMessages(['operation_id' => 'Context Maintenance operation was reused with different input.']);
            }

            return $this->authorizeCheckpoint($actor, $organization, $conversation, $session, $existing);
        }

        $deltaText = $this->renderRows($delta, self::MAX_DELTA_CHARACTERS);
        $previous = $current?->structured_context ?? '{}';
        $messages = [
            ['role' => 'system', 'content' => 'Maintain bounded rolling context as JSON keys: current_topic, main_views, agreement_candidates, open_questions, to_confirm, source_refs. Preserve uncertainty and anonymous speaker provenance.'],
            ['role' => 'user', 'content' => "Previous checkpoint:\n{$previous}\nNew authorized transcript delta:\n{$deltaText}"],
        ];
        $estimated = $this->estimateTokens($messages);
        if ($estimated > self::MAX_INPUT_TOKENS) {
            throw ValidationException::withMessages(['context' => 'The bounded maintenance payload exceeds the conservative token budget.']);
        }
        $authorize = function () use ($actor, $organization, $conversation, $session, $revisionIds, $messages): array {
            $this->authorizeContext($actor->fresh(), $organization, $conversation->fresh(), $session->fresh());
            if ($this->currentRows($session->fresh())->pluck('revision.id')->map(fn ($id): int => (int) $id)->all() !== $revisionIds) {
                throw ValidationException::withMessages(['context' => 'Transcript Revision changed before Context Maintenance attempt.']);
            }

            return ['messages' => $messages, 'sources' => []];
        };
        $result = $this->gateway->respond($actor, $conversation, $messages, [], (string) Str::uuid(), $authorize, self::PURPOSE_MAINTENANCE);
        $authorize();
        $structured = $this->normalizeStructured((string) $result['answer']);

        return DB::transaction(function () use ($actor, $organization, $conversation, $session, $operationId, $payloadFingerprint, $audience, $rows, $delta, $current, $structured, $estimated, $result, $revisionIds): AiCommonSharedContextCheckpoint {
            $locked = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->authorizeContext($actor->fresh(), $organization, $conversation->fresh(), $locked);
            if ($this->currentRows($locked)->pluck('revision.id')->map(fn ($id): int => (int) $id)->all() !== $revisionIds) {
                throw ValidationException::withMessages(['context' => 'Transcript Revision changed before checkpoint publication.']);
            }
            if ($current) {
                $current->fresh()->update(['status' => AiCommonSharedContextCheckpoint::STATUS_STALE]);
            }
            $this->createChunks($locked, $delta);
            $last = $rows->last();
            $checkpoint = AiCommonSharedContextCheckpoint::query()->create([
                'ai_common_shared_session_id' => $locked->id,
                'previous_checkpoint_id' => $current?->id,
                'created_by_user_id' => $actor->id,
                'purpose_revision_id' => $locked->purpose_revision_id,
                'revision_no' => ($current?->revision_no ?? 0) + 1,
                'operation_id' => $operationId,
                'payload_fingerprint' => $payloadFingerprint,
                'audience_fingerprint' => $audience['fingerprint'],
                'through_segment_id' => $last['segment']->id,
                'through_revision_id' => $last['revision']->id,
                'status' => AiCommonSharedContextCheckpoint::STATUS_CURRENT,
                'structured_context' => json_encode($structured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'estimated_tokens' => $estimated,
                'lineage_fingerprint' => hash('sha256', implode('|', $revisionIds)),
                'provider' => $result['provider'] ?? null,
                'model' => $result['model'] ?? null,
            ]);
            foreach ($rows as $row) {
                $identity = $row['identity'];
                AiCommonSharedCheckpointDependency::query()->create([
                    'ai_common_shared_context_checkpoint_id' => $checkpoint->id,
                    'transcript_segment_id' => $row['segment']->id,
                    'transcript_revision_id' => $row['revision']->id,
                    'identity_revision_id' => $identity?->id,
                    'speaker_label' => $row['segment']->speaker_label,
                    'speaker_scope' => $row['segment']->speaker_scope,
                    'range_start_ms' => $row['revision']->range_start_ms,
                    'range_end_ms' => $row['revision']->range_end_ms,
                    'dependency_fingerprint' => $this->dependencyFingerprint($row),
                ]);
            }
            $locked->update([
                'context_current_checkpoint_id' => $checkpoint->id,
                'context_watermark_segment_id' => $last['segment']->id,
                'context_watermark_revision_id' => $last['revision']->id,
                'context_dirty_from_segment_id' => null,
                'context_status' => 'current',
                'room_sequence' => $locked->room_sequence + 1,
            ]);
            AiCommonSharedCoState::query()->where('current_session_id', $locked->id)->update([
                'context_state' => 'current',
                'context_watermark_segment_id' => $last['segment']->id,
                'room_sequence' => $locked->room_sequence,
            ]);

            return $checkpoint;
        }, 3);
    }

    public function contextForRequest(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, string $query): array
    {
        $checkpoint = $this->currentCheckpoint($session->fresh());
        if (! $checkpoint) {
            return ['messages' => [], 'checkpoint_id' => null, 'dependencies' => []];
        }
        $checkpoint = $this->authorizeCheckpoint($actor, $organization, $conversation, $session, $checkpoint);
        $recent = $this->renderRows($this->currentRows($session)->take(-20), self::MAX_RECENT_CHARACTERS);
        $historical = collect($this->historical($actor, $organization, $conversation, $session, $query))
            ->pluck('content')->implode("\n");
        $purpose = $session->purposeRevision()->value('purpose');
        $content = "Conversation Purpose: {$purpose}\nRolling Context: {$checkpoint->structured_context}\nRecent Transcript:\n{$recent}\nRelevant Historical Transcript:\n{$historical}";
        if ($this->estimateTokens([['role' => 'system', 'content' => $content]]) > self::MAX_INPUT_TOKENS) {
            throw ValidationException::withMessages(['context' => 'Authorized context cannot fit the bounded request budget.']);
        }

        return [
            'messages' => [['role' => 'system', 'content' => $content]],
            'checkpoint_id' => $checkpoint->id,
            'dependencies' => $checkpoint->dependencies()->pluck('dependency_fingerprint')->all(),
        ];
    }

    public function historical(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, string $query): array
    {
        $this->authorizeContext($actor, $organization, $conversation, $session);
        $tokens = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 2)->unique()->take(10);
        $remaining = self::MAX_HISTORICAL_CHARACTERS;

        return AiCommonSharedTranscriptChunk::query()
            ->where('ai_common_shared_session_id', $session->id)
            ->where('status', 'current')
            ->orderByDesc('ordinal')->limit(100)->get()
            ->map(function ($chunk) use ($tokens): array {
                $text = (string) $chunk->content;
                $score = $tokens->sum(fn (string $token): int => mb_substr_count(mb_strtolower($text), $token));

                return ['chunk' => $chunk, 'score' => $score, 'content' => $text];
            })->filter(fn (array $row): bool => $tokens->isEmpty() || $row['score'] > 0)
            ->sortByDesc('score')->take(self::MAX_HISTORICAL_CHUNKS)
            ->map(function (array $row) use (&$remaining): array {
                $text = mb_substr($row['content'], 0, max(0, $remaining));
                $remaining -= mb_strlen($text);

                return [
                    'chunk_id' => $row['chunk']->public_id,
                    'range_start_ms' => $row['chunk']->range_start_ms,
                    'range_end_ms' => $row['chunk']->range_end_ms,
                    'content' => $text,
                ];
            })->filter(fn (array $row): bool => $row['content'] !== '')->values()->all();
    }

    public function snapshot(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, string $clientInstanceId, int $cursor): array
    {
        if (! Str::isUuid($clientInstanceId) || $cursor < 0) {
            throw ValidationException::withMessages(['cursor' => 'A valid device cursor is required.']);
        }
        $this->authorizeContext($actor, $organization, $conversation, $session, false);
        $state = AiCommonSharedCoState::query()->where('ai_common_shared_conversation_id', $session->ai_common_shared_conversation_id)->firstOrFail();
        $sequence = max((int) $state->room_sequence, (int) $session->room_sequence, (int) $state->sequence);
        $row = AiCommonSharedDeviceCursor::query()->firstOrNew(['ai_common_shared_session_id' => $session->id, 'client_instance_id' => $clientInstanceId]);
        if ($row->exists && $row->user_id !== $actor->id) {
            throw ValidationException::withMessages(['cursor' => 'This device cursor belongs to another Participant.']);
        }
        $row->fill(['user_id' => $actor->id, 'last_sequence' => $sequence, 'version' => ($row->version ?? 0) + 1, 'last_seen_at_utc' => now('UTC')])->save();

        return [
            'sequence' => $sequence,
            'resync_required' => $cursor > $sequence || ($sequence - $cursor) > 100,
            'session_id' => $session->public_id,
            'session_state' => $session->state,
            'request_id' => $state->current_request_id,
            'response_id' => $state->current_response_message_id,
            'state' => $state->state,
            'phase' => $state->phase,
            'presence' => $state->presence_state,
            'capture' => $state->capture_state,
            'asr' => $state->asr_state,
            'context' => $state->context_state,
            'context_watermark_segment_id' => $state->context_watermark_segment_id,
        ];
    }

    public function organize(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, string $operationId): AiCommonSharedSessionEndRun
    {
        if (! Str::isUuid($operationId) || $session->state !== AiCommonSharedSession::STATE_ENDED) {
            throw ValidationException::withMessages(['session' => 'Explicit organization requires an ended Session and valid operation.']);
        }
        $checkpoint = $this->authorizeCheckpoint($actor, $organization, $conversation, $session, $this->currentCheckpoint($session) ?? throw ValidationException::withMessages(['context' => 'A current checkpoint is required.']));
        $fingerprint = hash('sha256', $session->id.'|'.$checkpoint->id.'|organize');
        if ($existing = AiCommonSharedSessionEndRun::query()->where('ai_common_shared_session_id', $session->id)->where('operation_id', $operationId)->first()) {
            if (! hash_equals($existing->payload_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['operation_id' => 'Session-end operation was reused with different context.']);
            }

            return $existing->load('candidates');
        }
        $logical = (string) Str::uuid();
        $messages = [
            ['role' => 'system', 'content' => 'Return bounded candidate lines only: decision, unresolved, action_candidate, rationale, or next_check. Candidates are not official records and require human review.'],
            ['role' => 'user', 'content' => $checkpoint->structured_context],
        ];
        $authorize = function () use ($actor, $organization, $conversation, $session, $checkpoint, $messages): array {
            $this->authorizeCheckpoint($actor->fresh(), $organization, $conversation->fresh(), $session->fresh(), $checkpoint->fresh());

            return ['messages' => $messages, 'sources' => []];
        };
        $result = $this->gateway->respond($actor, $conversation, $messages, [], $logical, $authorize, self::PURPOSE_CO);
        $authorize();

        return DB::transaction(function () use ($actor, $session, $operationId, $logical, $fingerprint, $checkpoint, $result): AiCommonSharedSessionEndRun {
            $run = AiCommonSharedSessionEndRun::query()->create([
                'ai_common_shared_session_id' => $session->id,
                'actor_user_id' => $actor->id,
                'context_checkpoint_id' => $checkpoint->id,
                'operation_id' => $operationId,
                'logical_request_id' => $logical,
                'payload_fingerprint' => $fingerprint,
                'state' => 'candidates_ready',
            ]);
            $lines = collect(preg_split('/\R/u', trim((string) $result['answer'])) ?: [])->filter()->take(20)->values();
            if ($lines->isEmpty()) {
                $lines = collect(['to_confirm: Provider returned no bounded organization candidate.']);
            }
            $provenance = json_encode([
                'checkpoint_id' => $checkpoint->public_id,
                'dependencies' => $checkpoint->dependencies()->pluck('dependency_fingerprint')->all(),
            ], JSON_THROW_ON_ERROR);
            foreach ($lines as $index => $line) {
                $parts = explode(':', $line, 2);
                $kind = in_array(trim($parts[0]), ['decision', 'unresolved', 'action_candidate', 'rationale', 'next_check'], true) ? trim($parts[0]) : 'to_confirm';
                $content = trim($parts[1] ?? $line);
                AiCommonSharedSessionEndCandidate::query()->create([
                    'session_end_run_id' => $run->id,
                    'kind' => $kind,
                    'ordinal' => $index + 1,
                    'content' => $content,
                    'content_sha256' => hash('sha256', $content),
                    'provenance' => $provenance,
                ]);
            }

            return $run->load('candidates');
        }, 3);
    }

    public function authorizeCheckpoint(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, AiCommonSharedContextCheckpoint $checkpoint): AiCommonSharedContextCheckpoint
    {
        $this->authorizeContext($actor, $organization, $conversation, $session);
        $audience = $this->sharedAccess->audienceSnapshot($actor, $organization, $conversation);
        if ($checkpoint->ai_common_shared_session_id !== $session->id
            || $checkpoint->status !== AiCommonSharedContextCheckpoint::STATUS_CURRENT
            || $checkpoint->purpose_revision_id !== $session->purpose_revision_id
            || ! hash_equals($checkpoint->audience_fingerprint, $audience['fingerprint'])) {
            throw ValidationException::withMessages(['context' => 'The Rolling Context is stale or outside the current audience.']);
        }
        $dependencies = $checkpoint->dependencies()->get();
        foreach ($dependencies as $dependency) {
            $segment = $session->transcriptSegments()->with('identityRevisions')->find($dependency->transcript_segment_id);
            $identity = $segment?->identityRevisions->sortByDesc('revision_no')->first();
            if (! $segment || $segment->current_revision_id !== $dependency->transcript_revision_id
                || ($identity?->id) !== $dependency->identity_revision_id) {
                $checkpoint->update(['status' => AiCommonSharedContextCheckpoint::STATUS_STALE]);
                $session->update(['context_status' => 'lagging', 'context_dirty_from_segment_id' => min($session->context_dirty_from_segment_id ?? PHP_INT_MAX, $dependency->transcript_segment_id)]);
                throw ValidationException::withMessages(['context' => 'Transcript or Identity Revision changed; rebuild the checkpoint.']);
            }
        }

        return $checkpoint;
    }

    private function authorizeContext(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonSharedSession $session, bool $requireAiConsent = true): void
    {
        $this->sessionAccess->authorize($actor, $organization, $conversation, $session);
        $purposes = [AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING];
        if ($requireAiConsent) {
            $this->commonAccess->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
            $purposes[] = AiCommonSharedSessionConsent::PURPOSE_AI_REFERENCE;
        }
        $this->sessionAccess->assertConsents($actor, $organization, $conversation, $session, $purposes);
    }

    private function currentCheckpoint(AiCommonSharedSession $session): ?AiCommonSharedContextCheckpoint
    {
        if (! $session->context_current_checkpoint_id) {
            return null;
        }

        return AiCommonSharedContextCheckpoint::query()->with('dependencies')->find($session->context_current_checkpoint_id);
    }

    private function currentRows(AiCommonSharedSession $session): Collection
    {
        return $session->transcriptSegments()->with(['currentRevision', 'identityRevisions'])->orderBy('id')->get()->map(function ($segment): array {
            return [
                'segment' => $segment,
                'revision' => $segment->currentRevision,
                'identity' => $segment->identityRevisions->sortByDesc('revision_no')->first(),
            ];
        })->filter(fn (array $row): bool => $row['revision'] !== null)->values();
    }

    private function renderRows(Collection $rows, int $limit): string
    {
        $text = '';
        foreach ($rows as $row) {
            $identity = $row['identity'];
            $speaker = $identity?->status === AiCommonSharedIdentityRevision::STATUS_CONFIRMED
                ? 'confirmed-person-revision:'.$identity->public_id
                : $row['segment']->speaker_label.'@'.$row['segment']->speaker_scope;
            $line = '['.$row['revision']->range_start_ms.'-'.$row['revision']->range_end_ms.']['.$speaker.'] '.$row['revision']->content."\n";
            if (mb_strlen($text.$line) > $limit) {
                break;
            }
            $text .= $line;
        }

        return trim($text);
    }

    private function createChunks(AiCommonSharedSession $session, Collection $rows): void
    {
        $ordinal = (int) AiCommonSharedTranscriptChunk::query()->where('ai_common_shared_session_id', $session->id)->max('ordinal');
        $batch = collect();
        $characters = 0;
        $flush = function () use ($session, &$ordinal, &$batch, &$characters): void {
            if ($batch->isEmpty()) {
                return;
            }
            $content = $this->renderRows($batch, self::MAX_CHUNK_CHARACTERS);
            $first = $batch->first();
            $last = $batch->last();
            AiCommonSharedTranscriptChunk::query()->create([
                'ai_common_shared_session_id' => $session->id,
                'ordinal' => ++$ordinal,
                'first_segment_id' => $first['segment']->id,
                'last_segment_id' => $last['segment']->id,
                'range_start_ms' => $first['revision']->range_start_ms,
                'range_end_ms' => $last['revision']->range_end_ms,
                'content' => $content,
                'content_sha256' => hash('sha256', $content),
                'character_count' => mb_strlen($content),
            ]);
            $batch = collect();
            $characters = 0;
        };
        foreach ($rows as $row) {
            $length = mb_strlen((string) $row['revision']->content) + 120;
            if ($characters > 0 && $characters + $length > self::MAX_CHUNK_CHARACTERS) {
                $flush();
            }
            $batch->push($row);
            $characters += $length;
        }
        $flush();
    }

    private function normalizeStructured(string $answer): array
    {
        $decoded = json_decode($answer, true);
        $keys = ['current_topic', 'main_views', 'agreement_candidates', 'open_questions', 'to_confirm', 'source_refs'];
        if (! is_array($decoded)) {
            $decoded = ['to_confirm' => [mb_substr(trim($answer), 0, 4000)]];
        }

        return collect($keys)->mapWithKeys(function (string $key) use ($decoded): array {
            $value = $decoded[$key] ?? [];
            $items = is_array($value) ? $value : [$value];

            return [$key => collect($items)->filter(fn ($item): bool => is_scalar($item))->map(fn ($item): string => mb_substr(trim((string) $item), 0, 1000))->filter()->take(20)->values()->all()];
        })->all();
    }

    private function dependencyFingerprint(array $row): string
    {
        return hash('sha256', implode('|', [
            $row['segment']->id, $row['revision']->id, $row['identity']?->id ?? 'unknown',
            $row['segment']->speaker_scope, $row['revision']->content_sha256,
        ]));
    }

    private function estimateTokens(array $messages): int
    {
        $characters = collect($messages)->sum(fn (array $message): int => mb_strlen((string) ($message['content'] ?? '')));

        return (int) ceil($characters / 3);
    }
}
