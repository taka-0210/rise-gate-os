<?php

namespace App\Services\AiCommon;

use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\AiCommonSharedAiRequest;
use App\Models\AiCommonSharedContextCheckpoint;
use App\Models\AiCommonSharedCoState;
use App\Models\AiCommonSharedMessageAuthor;
use App\Models\AiCommonSharedSession;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiCommonSharedCoWriter
{
    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly AiCommonSharedAccess $access,
        private readonly AiCommonSharedContext $context,
        private readonly AiCommonSharedConversationReader $reader,
        private readonly AiCommonGateway $gateway,
        private readonly AiCommonSharedLongContext $longContext,
    ) {}

    public function request(User $actor, Organization $organization, AiCommonConversation $conversation, array $input): AiCommonSharedAiRequest
    {
        $operationId = (string) ($input['operation_id'] ?? '');
        $content = trim((string) ($input['content'] ?? ''));
        $sourceIds = collect($input['source_ids'] ?? [])->map(fn ($id): int => (int) $id)->filter()->unique()->sort()->values();
        if (! Str::isUuid($operationId) || $content === '' || mb_strlen($content) > 4000 || $sourceIds->count() > 20) {
            throw ValidationException::withMessages(['request' => 'A valid operation, content, and bounded source set are required.']);
        }
        $payloadFingerprint = hash('sha256', json_encode(['content' => $content, 'sources' => $sourceIds->all()], JSON_THROW_ON_ERROR));
        $this->common->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $this->access->audienceSnapshot($actor, $organization, $conversation);
        if ($existing = $this->existing($conversation, $actor, $operationId, $payloadFingerprint)) {
            return $existing;
        }

        $created = false;
        $request = DB::transaction(function () use ($actor, $organization, $conversation, $operationId, $content, $sourceIds, $payloadFingerprint, &$created): AiCommonSharedAiRequest {
            $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $this->common->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
            $audience = $this->access->audienceSnapshot($actor, $organization, $locked);
            if ($existing = $this->existing($locked, $actor, $operationId, $payloadFingerprint)) {
                return $existing;
            }
            $state = AiCommonSharedCoState::query()->where('ai_common_shared_conversation_id', $audience['shared']->id)->lockForUpdate()->first();
            $state ??= AiCommonSharedCoState::query()->create(['ai_common_shared_conversation_id' => $audience['shared']->id]);
            if ($state->state === AiCommonSharedAiRequest::STATE_PROCESSING) {
                throw ValidationException::withMessages(['request' => 'A Shared CO request is already processing.']);
            }
            $selected = $locked->sources()->whereIn('id', $sourceIds)->get();
            if ($selected->count() !== $sourceIds->count()) {
                throw ValidationException::withMessages(['sources' => 'A selected Shared source is unavailable.']);
            }
            $this->context->revisionsForSelections($actor, $organization, $selected);
            $message = $locked->messages()->create(['role' => AiCommonMessage::ROLE_USER, 'content' => $content, 'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE]);
            $participant = $audience['participants']->firstWhere('user_id', $actor->id);
            $membership = $this->common->authorizeOrganization($actor, $organization);
            AiCommonSharedMessageAuthor::query()->create([
                'ai_common_message_id' => $message->id,
                'ai_common_shared_conversation_id' => $audience['shared']->id,
                'ai_common_shared_participant_id' => $participant->id,
                'author_user_id' => $actor->id,
                'participant_audience_epoch' => $participant->audience_epoch,
                'membership_access_epoch' => $membership->access_epoch,
                'credential_generation' => $actor->credential_generation,
            ]);
            $sequence = (int) $state->sequence + 1;
            $request = AiCommonSharedAiRequest::query()->create([
                'ai_common_shared_conversation_id' => $audience['shared']->id,
                'actor_participant_id' => $participant->id,
                'actor_user_id' => $actor->id,
                'purpose_revision_id' => $audience['shared']->current_purpose_revision_id,
                'request_message_id' => $message->id,
                'operation_id' => $operationId,
                'logical_request_id' => $operationId,
                'payload_fingerprint' => $payloadFingerprint,
                'audience_fingerprint' => $audience['fingerprint'],
                'state' => AiCommonSharedAiRequest::STATE_PROCESSING,
                'phase' => 'queued',
                'sequence' => $sequence,
            ]);
            $created = true;
            $state->update(['current_request_id' => $request->id, 'current_response_message_id' => null, 'state' => AiCommonSharedAiRequest::STATE_PROCESSING, 'phase' => 'queued', 'presence_state' => 'queued', 'sequence' => $sequence, 'room_sequence' => $state->room_sequence + 1, 'version' => $state->version + 1]);
            $locked->update(['last_message_at' => now(), 'version' => $locked->version + 1]);

            return $request;
        }, 3);

        if (! $created) {
            return $request;
        }

        try {
            $checkpointId = null;
            $session = $conversation->sharedConversation()->first()?->sessions()
                ->whereIn('state', [AiCommonSharedSession::STATE_ACTIVE, AiCommonSharedSession::STATE_PAUSED, AiCommonSharedSession::STATE_ENDED])
                ->latest('id')->first();
            if ($session && $session->transcriptSegments()->exists()) {
                $checkpoint = $this->longContext->maintain($actor, $organization, $conversation, $session, (string) Str::uuid());
                $checkpointId = $checkpoint?->id;
                if ($checkpointId) {
                    $request->update(['context_checkpoint_id' => $checkpointId]);
                }
            }

            AiCommonSharedCoState::query()->where('current_request_id', $request->id)->update([
                'presence_state' => 'provider_processing',
                'phase' => 'provider_processing',
            ]);
            $result = $this->gateway->respond($actor, $conversation, [], [], $request->logical_request_id,
                fn (): array => $this->authorizedAttempt($actor, $organization, $conversation, $sourceIds->all(), $request->purpose_revision_id, $checkpointId, $content),
                AiCommonSharedLongContext::PURPOSE_CO);
            $authorized = $result['_authorized_context'];
            $allowedHandles = collect($authorized['sources'])->pluck('handle');
            if (collect($result['citations'] ?? [])->diff($allowedHandles)->isNotEmpty()) {
                throw ValidationException::withMessages(['citation' => 'Provider cited a source outside the authorized Shared context.']);
            }

            return DB::transaction(function () use ($request, $actor, $organization, $conversation, $result, $authorized, $allowedHandles): AiCommonSharedAiRequest {
                $locked = AiCommonSharedAiRequest::query()->lockForUpdate()->findOrFail($request->id);
                $state = AiCommonSharedCoState::query()->where('ai_common_shared_conversation_id', $locked->ai_common_shared_conversation_id)->lockForUpdate()->firstOrFail();
                if ($locked->state !== AiCommonSharedAiRequest::STATE_PROCESSING || $state->current_request_id !== $locked->id) {
                    throw ValidationException::withMessages(['request' => 'The Shared request is no longer publishable.']);
                }
                $requestContent = (string) AiCommonMessage::query()->whereKey($locked->request_message_id)->value('content');
                $publishContext = $this->authorizedAttempt($actor, $organization, $conversation, $authorized['source_ids'], $locked->purpose_revision_id, $locked->context_checkpoint_id, $requestContent);
                if ($publishContext['revision_ids'] !== $authorized['revision_ids']
                    || collect($publishContext['sources'])->pluck('handle')->all() !== collect($authorized['sources'])->pluck('handle')->all()) {
                    throw ValidationException::withMessages(['request' => 'Shared authorization changed before response publication.']);
                }
                $assistant = $conversation->messages()->create([
                    'role' => AiCommonMessage::ROLE_ASSISTANT,
                    'content' => (string) $result['answer'],
                    'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
                    'source_fingerprint' => hash('sha256', $allowedHandles->sort()->implode('|')),
                    'source_lineage_version' => AiCommonMessage::SOURCE_LINEAGE_V1,
                    'provider' => $result['provider'] ?? null,
                    'model' => $result['model'] ?? null,
                    'logical_request_id' => $result['logical_request_id'],
                ]);
                $assistant->sources()->sync($authorized['source_ids']);
                $assistant->sourceRevisions()->sync($authorized['revision_ids']);
                $locked->update(['response_message_id' => $assistant->id, 'state' => AiCommonSharedAiRequest::STATE_ANSWER_READY, 'phase' => 'published']);
                $state->update(['current_response_message_id' => $assistant->id, 'state' => AiCommonSharedAiRequest::STATE_ANSWER_READY, 'phase' => 'published', 'presence_state' => 'answer_ready', 'room_sequence' => $state->room_sequence + 1, 'version' => $state->version + 1]);
                $conversation->increment('version');

                return $locked->fresh();
            }, 3);
        } catch (Throwable $error) {
            DB::transaction(function () use ($request): void {
                $locked = AiCommonSharedAiRequest::query()->lockForUpdate()->find($request->id);
                if ($locked && $locked->state === AiCommonSharedAiRequest::STATE_PROCESSING) {
                    $locked->update(['state' => AiCommonSharedAiRequest::STATE_UNAVAILABLE, 'phase' => 'discarded', 'safe_error_code' => 'authorization_or_provider_changed']);
                    AiCommonSharedCoState::query()->where('current_request_id', $locked->id)->update(['state' => AiCommonSharedAiRequest::STATE_UNAVAILABLE, 'phase' => 'discarded', 'presence_state' => 'unavailable']);
                }
            });
            throw $error;
        }
    }

    private function authorizedAttempt(User $actor, Organization $organization, AiCommonConversation $conversation, array $sourceIds, int $purposeRevisionId, ?int $checkpointId = null, string $query = ''): array
    {
        $this->common->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        $audience = $this->access->audienceSnapshot($actor, $organization, $conversation);
        if ((int) $audience['shared']->current_purpose_revision_id !== $purposeRevisionId) {
            throw ValidationException::withMessages(['request' => 'Shared Purpose changed; start a new request.']);
        }
        $selected = $conversation->sources()->whereIn('id', $sourceIds)->get();
        if ($selected->count() !== count(array_unique($sourceIds))) {
            throw ValidationException::withMessages(['sources' => 'A selected Shared source is unavailable.']);
        }
        $history = $this->reader->providerContext($actor, $organization, $conversation);
        $direct = $this->context->revisionsForSelections($actor, $organization, $selected);
        $revisions = $history['revisions']->concat($direct)->unique('id')->values();
        $sources = $this->context->authorizedRevisions($actor, $organization, $revisions);
        $map = $direct->mapWithKeys(fn ($revision) => [$revision->ai_common_source_id => $revision->id])->all();
        $this->context->assertSelectionsStillPointTo($map);

        $purpose = $audience['shared']->currentPurposeRevision()->firstOrFail();
        $longMessages = [];
        if ($checkpointId) {
            $checkpoint = AiCommonSharedContextCheckpoint::query()->findOrFail($checkpointId);
            $session = AiCommonSharedSession::query()->findOrFail($checkpoint->ai_common_shared_session_id);
            $longMessages = $this->longContext->contextForRequest($actor, $organization, $conversation, $session, $query)['messages'];
        }

        return [
            'messages' => array_merge([['role' => 'system', 'content' => 'Shared Conversation purpose: '.$purpose->purpose]], $longMessages, $history['messages']),
            'sources' => $sources,
            'source_ids' => $selected->pluck('id')->all(),
            'revision_ids' => $revisions->pluck('id')->all(),
        ];
    }

    private function existing(AiCommonConversation $conversation, User $actor, string $operationId, string $fingerprint): ?AiCommonSharedAiRequest
    {
        $request = AiCommonSharedAiRequest::query()->where('ai_common_shared_conversation_id', $conversation->sharedConversation()->value('id'))->where('operation_id', $operationId)->first();
        if ($request && ($request->actor_user_id !== $actor->id || ! hash_equals($request->payload_fingerprint, $fingerprint))) {
            throw ValidationException::withMessages(['operation_id' => 'The operation ID was already used for different Shared content.']);
        }

        return $request;
    }
}
