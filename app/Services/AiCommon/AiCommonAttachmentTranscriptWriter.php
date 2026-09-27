<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonAttachmentTranscriptionOperation;
use App\Models\AiCommonConversation;
use App\Models\AiCommonTranscriptRevision;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiCommonAttachmentTranscriptWriter
{
    private const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'mp4', 'webm', 'wav'];

    public function __construct(
        private readonly AiCommonAccess $common,
        private readonly AiCommonAttachmentAccess $access,
        private readonly AiCommonTranscriptionProvider $provider,
        private readonly AiCommonTranscriptionEvidence $evidence,
    ) {}

    public function transcribe(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        string $operationId,
        bool $consented,
    ): AiCommonTranscriptRevision {
        if (! $consented || ! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['consent' => 'Explicit transcription consent and a valid operation ID are required.']);
        }
        $this->authorize($actor, $organization, $conversation, $attachment);
        $fingerprint = hash('sha256', $attachment->sha256.'|transcribe|'.$attachment->version);
        if ($existing = $this->existing($attachment, $actor, $operationId, $fingerprint)) {
            if ($existing->result_status === AiCommonAttachmentTranscriptionOperation::RESULT_SUCCESS) {
                return $existing->revision()->firstOrFail();
            }
            throw ValidationException::withMessages(['audio' => 'This transcription operation is already final.']);
        }
        $binary = $this->access->binary($actor, $organization, $conversation, $attachment);
        $logicalRequestId = (string) Str::uuid();
        try {
            DB::transaction(function () use ($actor, $organization, $conversation, $attachment, $operationId, $fingerprint, $logicalRequestId): void {
                $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
                $this->authorize($actor->fresh(), $organization, $conversation->fresh(), $locked);
                AiCommonAttachmentTranscriptionOperation::query()->create([
                    'ai_common_attachment_id' => $locked->id,
                    'actor_user_id' => $actor->id,
                    'operation_id' => $operationId,
                    'payload_fingerprint' => $fingerprint,
                    'logical_request_id' => $logicalRequestId,
                    'result_status' => AiCommonAttachmentTranscriptionOperation::RESULT_PROCESSING,
                ]);
            }, 3);
        } catch (QueryException $error) {
            $existing = $this->existing($attachment, $actor, $operationId, $fingerprint);
            if (! $existing) {
                throw $error;
            }
            if ($existing->result_status === AiCommonAttachmentTranscriptionOperation::RESULT_SUCCESS) {
                return $existing->revision()->firstOrFail();
            }

            throw ValidationException::withMessages(['audio' => 'This transcription operation is already being processed or is final.']);
        }

        $started = hrtime(true);
        $providerAttempted = false;
        try {
            // The final check immediately before I/O deliberately occurs outside any DB transaction.
            $this->authorize($actor->fresh(), $organization, $conversation->fresh(), $attachment->fresh());
            $providerAttempted = true;
            $result = $this->provider->transcribe($binary, $attachment->mime_type, $attachment->extension);
        } catch (AiCommonTranscriptionException $error) {
            $this->fail($organization, $conversation, $attachment, $actor, $operationId, $error->safeCode, $error->resultUnknown, $started, providerAttempted: $providerAttempted);
            throw ValidationException::withMessages(['audio' => $error->safeCode]);
        } catch (AuthorizationException|ValidationException $error) {
            $this->fail($organization, $conversation, $attachment, $actor, $operationId, 'transcription_authorization_changed', false, $started, providerAttempted: $providerAttempted);
            throw $error;
        } catch (Throwable $error) {
            $this->fail($organization, $conversation, $attachment, $actor, $operationId, 'transcription_result_unknown', $providerAttempted, $started, providerAttempted: $providerAttempted);
            throw $error;
        }

        try {
            return DB::transaction(function () use ($actor, $organization, $conversation, $attachment, $operationId, $result, $started): AiCommonTranscriptRevision {
                $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
                $this->authorize($actor->fresh(), $organization, $conversation->fresh(), $locked);
                if ($locked->version !== $attachment->version || ! hash_equals($locked->sha256, $attachment->sha256)) {
                    throw ValidationException::withMessages(['audio' => 'Audio changed while transcription was processing.']);
                }
                $number = (int) AiCommonTranscriptRevision::query()
                    ->where('ai_common_attachment_id', $locked->id)->max('revision_number') + 1;
                $text = trim((string) ($result['text'] ?? ''));
                if ($text === '' || mb_strlen($text) > 4000) {
                    throw ValidationException::withMessages(['audio' => 'Transcription returned unusable content.']);
                }
                $revision = AiCommonTranscriptRevision::query()->create([
                    'ai_common_attachment_id' => $locked->id,
                    'created_by_user_id' => $actor->id,
                    'operation_id' => $operationId,
                    'payload_fingerprint' => hash('sha256', $locked->sha256.'|provider|'.$text),
                    'revision_number' => $number,
                    'kind' => AiCommonTranscriptRevision::KIND_PROVIDER,
                    'content' => $text,
                    'content_sha256' => hash('sha256', $text),
                    'audio_sha256' => $locked->sha256,
                    'range_start_ms' => 0,
                    'range_end_ms' => $locked->duration_ms,
                    'range_precision' => $locked->duration_ms ? 'attachment' : 'unknown',
                    'provider' => $result['provider'],
                    'model' => $result['model'] ?? null,
                ]);
                $operation = AiCommonAttachmentTranscriptionOperation::query()
                    ->where('ai_common_attachment_id', $locked->id)
                    ->where('actor_user_id', $actor->id)
                    ->where('operation_id', $operationId)
                    ->lockForUpdate()->firstOrFail();
                $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
                $operation->update([
                    'ai_common_transcript_revision_id' => $revision->id,
                    'result_status' => AiCommonAttachmentTranscriptionOperation::RESULT_SUCCESS,
                    'provider' => $result['provider'],
                    'model' => $result['model'] ?? null,
                    'latency_ms' => $latencyMs,
                ]);
                $this->evidence->record(
                    $organization,
                    $conversation,
                    $actor,
                    $operationId,
                    $operation->logical_request_id,
                    'attachment',
                    $locked->public_id,
                    $result['provider'],
                    $result['model'] ?? null,
                    AiCommonAttachmentTranscriptionOperation::RESULT_SUCCESS,
                    null,
                    $latencyMs,
                    $locked->duration_ms,
                    $result['usage'] ?? [],
                );

                return $revision;
            }, 3);
        } catch (AuthorizationException|ValidationException $error) {
            $this->fail($organization, $conversation, $attachment, $actor, $operationId, 'transcription_authorization_changed', false, $started, $result, true);
            throw $error;
        } catch (Throwable $error) {
            $this->fail($organization, $conversation, $attachment, $actor, $operationId, 'transcription_result_unknown', true, $started, $result);
            throw $error;
        }
    }

    public function revise(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        AiCommonTranscriptRevision $parent,
        string $operationId,
        string $content,
    ): AiCommonTranscriptRevision {
        $this->authorize($actor, $organization, $conversation, $attachment);
        if ($parent->ai_common_attachment_id !== $attachment->id) {
            throw new AuthorizationException;
        }
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid operation ID is required.']);
        }
        $content = trim($content);
        if ($content === '' || mb_strlen($content) > 4000) {
            throw ValidationException::withMessages(['content' => 'Transcript revision must contain 1 to 4000 characters.']);
        }

        $fingerprint = hash('sha256', $parent->id.'|'.$content);
        $existing = AiCommonTranscriptRevision::query()
            ->where('ai_common_attachment_id', $attachment->id)
            ->where('created_by_user_id', $actor->id)
            ->where('operation_id', $operationId)->first();
        if ($existing) {
            if (! hash_equals((string) $existing->payload_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['operation_id' => 'The operation ID is already used for another transcript revision.']);
            }

            return $existing;
        }
        try {
            return DB::transaction(function () use ($actor, $organization, $conversation, $attachment, $parent, $operationId, $fingerprint, $content): AiCommonTranscriptRevision {
                $locked = AiCommonAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
                $this->authorize($actor->fresh(), $organization, $conversation->fresh(), $locked);
                $number = (int) AiCommonTranscriptRevision::query()
                    ->where('ai_common_attachment_id', $locked->id)->max('revision_number') + 1;

                return AiCommonTranscriptRevision::query()->create([
                    'ai_common_attachment_id' => $locked->id,
                    'created_by_user_id' => $actor->id,
                    'parent_revision_id' => $parent->id,
                    'operation_id' => $operationId,
                    'payload_fingerprint' => $fingerprint,
                    'revision_number' => $number,
                    'kind' => AiCommonTranscriptRevision::KIND_HUMAN,
                    'content' => $content,
                    'content_sha256' => hash('sha256', $content),
                    'audio_sha256' => $locked->sha256,
                    'range_start_ms' => $parent->range_start_ms,
                    'range_end_ms' => $parent->range_end_ms,
                    'range_precision' => $parent->range_precision,
                ]);
            }, 3);
        } catch (QueryException $error) {
            $existing = AiCommonTranscriptRevision::query()
                ->where('ai_common_attachment_id', $attachment->id)
                ->where('created_by_user_id', $actor->id)
                ->where('operation_id', $operationId)->first();
            if ($existing && hash_equals((string) $existing->payload_fingerprint, $fingerprint)) {
                return $existing;
            }
            throw $error;
        }
    }

    private function authorize(User $actor, Organization $organization, AiCommonConversation $conversation, AiCommonAttachment $attachment): void
    {
        $this->access->authorizeConversation($actor, $organization, $conversation, true);
        $this->access->authorizeAttachment($actor, $organization, $conversation, $attachment, true);
        if ($attachment->uploaded_by_user_id !== $actor->id
            || ! in_array($attachment->extension, self::AUDIO_EXTENSIONS, true)) {
            throw new AuthorizationException;
        }
        $policy = $this->common->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        if (! $policy->allows_transcription) {
            throw new AuthorizationException;
        }
    }

    private function existing(AiCommonAttachment $attachment, User $actor, string $operationId, string $fingerprint): ?AiCommonAttachmentTranscriptionOperation
    {
        $existing = AiCommonAttachmentTranscriptionOperation::query()
            ->where('ai_common_attachment_id', $attachment->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation_id', $operationId)->first();
        if ($existing && ! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => 'The operation ID is already used for another transcription.']);
        }

        return $existing;
    }

    private function fail(
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonAttachment $attachment,
        User $actor,
        string $operationId,
        string $safeCode,
        bool $unknown,
        int $started,
        ?array $providerResult = null,
        bool $discarded = false,
        bool $providerAttempted = true,
    ): void {
        DB::transaction(function () use ($organization, $conversation, $attachment, $actor, $operationId, $safeCode, $unknown, $started, $providerResult, $discarded, $providerAttempted): void {
            $operation = AiCommonAttachmentTranscriptionOperation::query()
                ->where('ai_common_attachment_id', $attachment->id)
                ->where('actor_user_id', $actor->id)
                ->where('operation_id', $operationId)
                ->where('result_status', AiCommonAttachmentTranscriptionOperation::RESULT_PROCESSING)
                ->lockForUpdate()
                ->first();
            if (! $operation) {
                return;
            }
            $status = $discarded
                ? AiCommonAttachmentTranscriptionOperation::RESULT_DISCARDED
                : ($unknown
                    ? AiCommonAttachmentTranscriptionOperation::RESULT_UNKNOWN
                    : AiCommonAttachmentTranscriptionOperation::RESULT_FAILED);
            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $provider = $providerResult['provider'] ?? $operation->provider ?? 'unknown';
            $model = $providerResult['model'] ?? $operation->model;
            $operation->update([
                'result_status' => $status,
                'safe_error_code' => $safeCode,
                'provider' => $provider,
                'model' => $model,
                'latency_ms' => $latencyMs,
            ]);
            $this->evidence->record(
                $organization,
                $conversation,
                $actor,
                $operationId,
                $operation->logical_request_id,
                'attachment',
                $attachment->public_id,
                $provider,
                $model,
                $status,
                $safeCode,
                $latencyMs,
                $attachment->duration_ms,
                $providerResult['usage'] ?? [],
                $providerAttempted,
            );
        }, 3);
    }
}
