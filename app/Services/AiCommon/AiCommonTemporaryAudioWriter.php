<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonConversation;
use App\Models\AiCommonInputOperation;
use App\Models\AiCommonMessage;
use App\Models\AiCommonTemporaryAudio;
use App\Models\AiCommonTranscriptionOperation;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiCommonTemporaryAudioWriter
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_DURATION_MS = 3 * 60 * 1000;

    private const EXTENSIONS = ['mp3', 'm4a', 'mp4', 'webm', 'wav'];

    public function __construct(
        private readonly AiCommonAccess $access,
        private readonly AiCommonAudioInspector $inspector,
        private readonly AiCommonTranscriptionProvider $provider,
        private readonly AiCommonHumanMessageWriter $messages,
    ) {}

    public function record(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        UploadedFile $file,
        string $operationId,
    ): AiCommonTemporaryAudio {
        $this->access->authorizeConversation($actor, $organization, $conversation, true);
        if (! Str::isUuid($operationId) || ! $file->isValid()) {
            throw ValidationException::withMessages(['voice' => 'Voice Inputを受け付けられません。']);
        }
        $binary = file_get_contents($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        if (! is_string($binary) || $binary === '' || strlen($binary) > self::MAX_BYTES
            || ! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['voice' => 'Voice Inputは許可形式・10 MiB以内にしてください。']);
        }
        $hash = hash('sha256', $binary);
        $fingerprint = hash('sha256', implode('|', [$hash, $extension, $mime, strlen($binary)]));
        $existing = $this->existingRecording($conversation, $actor, $operationId, $fingerprint);
        if ($existing) {
            return $existing;
        }
        try {
            $inspection = $this->inspector->inspect($binary, $extension, $mime);
        } catch (AiCommonInspectionUnavailable $error) {
            throw ValidationException::withMessages(['voice' => $error->safeCode]);
        }
        if ($inspection['duration_ms'] < 1 || $inspection['duration_ms'] > self::MAX_DURATION_MS) {
            throw ValidationException::withMessages(['voice' => 'Voice Inputは3分以内にしてください。']);
        }

        $publicId = (string) Str::ulid();
        $storageKey = $organization->public_id.'/'.$conversation->public_id.'/'.$publicId.'.enc';
        Storage::disk('ai_common_temporary_audio')->put(
            $storageKey,
            Crypt::encryptString(base64_encode($binary)),
        );
        try {
            $result = DB::transaction(function () use (
                $actor,
                $organization,
                $conversation,
                $operationId,
                $fingerprint,
                $publicId,
                $storageKey,
                $inspection,
                $hash,
                $binary,
            ): AiCommonTemporaryAudio {
                $locked = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
                $this->access->authorizeConversation($actor, $organization, $locked, true);
                if ($existing = $this->existingRecording($locked, $actor, $operationId, $fingerprint)) {
                    return $existing;
                }

                return AiCommonTemporaryAudio::query()->create([
                    'public_id' => $publicId,
                    'organization_id' => $organization->id,
                    'ai_common_conversation_id' => $locked->id,
                    'actor_user_id' => $actor->id,
                    'operation_id' => $operationId,
                    'payload_fingerprint' => $fingerprint,
                    'state' => AiCommonTemporaryAudio::STATE_RECORDED,
                    'version' => 1,
                    'storage_key' => $storageKey,
                    'mime_type' => $inspection['mime_type'],
                    'extension' => $inspection['extension'],
                    'size_bytes' => strlen($binary),
                    'sha256' => $hash,
                    'codec' => $inspection['codec'],
                    'duration_ms' => $inspection['duration_ms'],
                    'expires_at_utc' => now('UTC')->addHour(),
                    'cleanup_status' => AiCommonTemporaryAudio::CLEANUP_PENDING,
                ]);
            }, 3);
            if ($result->storage_key !== $storageKey) {
                try {
                    Storage::disk('ai_common_temporary_audio')->delete($storageKey);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }

            return $result;
        } catch (Throwable $error) {
            Storage::disk('ai_common_temporary_audio')->delete($storageKey);
            if ($existing = $this->existingRecording($conversation, $actor, $operationId, $fingerprint)) {
                return $existing;
            }
            throw $error;
        }
    }

    public function transcribe(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
        string $operationId,
        bool $consented,
    ): AiCommonTemporaryAudio {
        if (! $consented || ! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['consent' => '文字起こし送信への本人同意が必要です。']);
        }
        $this->authorizeTranscription($actor, $organization, $conversation, $audio);
        $fingerprint = hash('sha256', $audio->sha256.'|transcribe');
        $existing = $this->existingTranscription($audio, $actor, $operationId, $fingerprint);
        if ($existing?->result_status === AiCommonTranscriptionOperation::RESULT_SUCCESS) {
            return $audio->fresh();
        }
        if ($existing) {
            throw ValidationException::withMessages(['voice' => 'この文字起こし操作は完了していません。']);
        }

        $logicalRequestId = (string) Str::uuid();
        $expectedVersion = DB::transaction(function () use (
            $actor,
            $organization,
            $conversation,
            $audio,
            $operationId,
            $fingerprint,
            $logicalRequestId,
        ): int {
            $locked = AiCommonTemporaryAudio::query()->lockForUpdate()->findOrFail($audio->id);
            $this->authorizeTranscription($actor, $organization, $conversation->fresh(), $locked);
            if (! in_array($locked->state, [AiCommonTemporaryAudio::STATE_RECORDED, AiCommonTemporaryAudio::STATE_FAILED], true)) {
                throw ValidationException::withMessages(['voice' => '現在のVoice Input状態では文字起こしできません。']);
            }
            AiCommonTranscriptionOperation::query()->create([
                'ai_common_temporary_audio_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'operation_id' => $operationId,
                'payload_fingerprint' => $fingerprint,
                'logical_request_id' => $logicalRequestId,
                'result_status' => AiCommonTranscriptionOperation::RESULT_PROCESSING,
            ]);
            $locked->update([
                'state' => AiCommonTemporaryAudio::STATE_TRANSCRIBING,
                'version' => $locked->version + 1,
                'transcription_consented_at_utc' => now('UTC'),
                'safe_error_code' => null,
            ]);

            return (int) $locked->version;
        }, 3);

        try {
            $encrypted = Storage::disk('ai_common_temporary_audio')->get($audio->storage_key);
            $binary = base64_decode(Crypt::decryptString($encrypted), true);
        } catch (Throwable) {
            $this->failTranscription($audio, $operationId, 'temporary_audio_integrity_failed', false);
            throw ValidationException::withMessages(['voice' => 'Temporary Audioの同一性を確認できません。']);
        }
        if (! is_string($binary) || ! hash_equals($audio->sha256, hash('sha256', $binary))) {
            $this->failTranscription($audio, $operationId, 'temporary_audio_integrity_failed', false);
            throw ValidationException::withMessages(['voice' => 'Temporary Audioの同一性を確認できません。']);
        }

        $started = hrtime(true);
        try {
            $this->authorizeTranscription($actor->fresh(), $organization, $conversation->fresh(), $audio->fresh());
            $result = $this->provider->transcribe($binary, $audio->mime_type, $audio->extension);
        } catch (AiCommonTranscriptionException $error) {
            $this->failTranscription($audio, $operationId, $error->safeCode, $error->resultUnknown, $started);
            throw ValidationException::withMessages(['voice' => $error->safeCode]);
        } catch (AuthorizationException|ValidationException $error) {
            $this->failTranscription($audio, $operationId, 'transcription_authorization_changed', false, $started);
            throw $error;
        } catch (Throwable $error) {
            $this->failTranscription($audio, $operationId, 'transcription_result_unknown', true, $started);
            throw new AiCommonTranscriptionException('transcription_result_unknown', true, $error);
        }

        try {
            return DB::transaction(function () use (
                $actor,
                $organization,
                $conversation,
                $audio,
                $operationId,
                $expectedVersion,
                $result,
                $started,
            ): AiCommonTemporaryAudio {
                $freshActor = $actor->fresh();
                $locked = AiCommonTemporaryAudio::query()->lockForUpdate()->findOrFail($audio->id);
                $this->authorizeTranscription($freshActor, $organization, $conversation->fresh(), $locked);
                if ($locked->version !== $expectedVersion || $locked->state !== AiCommonTemporaryAudio::STATE_TRANSCRIBING) {
                    throw ValidationException::withMessages(['voice' => 'Voice Input状態が処理中に変更されました。']);
                }
                $operation = AiCommonTranscriptionOperation::query()
                    ->where('ai_common_temporary_audio_id', $locked->id)
                    ->where('actor_user_id', $freshActor->id)
                    ->where('operation_id', $operationId)
                    ->lockForUpdate()->firstOrFail();
                $operation->update([
                    'provider' => $result['provider'],
                    'model' => $result['model'],
                    'result_status' => AiCommonTranscriptionOperation::RESULT_SUCCESS,
                    'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
                ]);
                $locked->update([
                    'state' => AiCommonTemporaryAudio::STATE_DRAFT,
                    'version' => $locked->version + 1,
                    'draft_text' => $result['text'],
                    'safe_error_code' => null,
                ]);

                return $locked->fresh();
            }, 3);
        } catch (AuthorizationException|ValidationException $error) {
            $this->failTranscription($audio, $operationId, 'transcription_authorization_changed', false, $started);
            throw $error;
        } catch (Throwable $error) {
            $this->failTranscription($audio, $operationId, 'transcription_result_unknown', true, $started);
            throw $error;
        }
    }

    public function postDraft(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
        string $operationId,
        string $content,
    ): AiCommonMessage {
        $this->authorizeAudio($actor, $organization, $conversation, $audio);
        $message = DB::transaction(function () use (
            $actor,
            $organization,
            $conversation,
            $audio,
            $operationId,
            $content,
        ): AiCommonMessage {
            $lockedConversation = AiCommonConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            $locked = AiCommonTemporaryAudio::query()->lockForUpdate()->findOrFail($audio->id);
            $this->authorizeAudio($actor, $organization, $lockedConversation, $locked);
            if ($locked->state === AiCommonTemporaryAudio::STATE_POSTED) {
                $operation = AiCommonInputOperation::query()
                    ->where('ai_common_conversation_id', $lockedConversation->id)
                    ->where('actor_user_id', $actor->id)
                    ->where('operation_id', $operationId)
                    ->where('command', AiCommonInputOperation::COMMAND_HUMAN_MESSAGE)
                    ->first();
                if ($operation?->ai_common_message_id !== $locked->posted_message_id) {
                    throw ValidationException::withMessages(['operation_id' => '投稿済みTranscriptには同じ操作IDだけを再送できます。']);
                }

                return $this->messages->post($actor, $organization, $lockedConversation, [
                    'operation_id' => $operationId,
                    'content' => $content,
                ]);
            }
            $this->authorizeAudio($actor, $organization, $lockedConversation, $locked, true);
            if ($locked->state !== AiCommonTemporaryAudio::STATE_DRAFT) {
                throw ValidationException::withMessages(['voice' => '確認可能なTranscript draftがありません。']);
            }
            $message = $this->messages->post($actor, $organization, $lockedConversation, [
                'operation_id' => $operationId,
                'content' => $content,
            ]);
            $locked->update([
                'state' => AiCommonTemporaryAudio::STATE_POSTED,
                'version' => $locked->version + 1,
                'posted_message_id' => $message->id,
                'draft_text' => null,
            ]);

            return $message;
        }, 3);
        $this->cleanup($audio->fresh());

        return $message;
    }

    public function cancel(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
    ): AiCommonTemporaryAudio {
        $this->authorizeAudio($actor, $organization, $conversation, $audio);
        DB::transaction(function () use ($actor, $organization, $conversation, $audio): void {
            $locked = AiCommonTemporaryAudio::query()->lockForUpdate()->findOrFail($audio->id);
            $this->authorizeAudio($actor, $organization, $conversation->fresh(), $locked);
            if (! in_array($locked->state, [AiCommonTemporaryAudio::STATE_POSTED, AiCommonTemporaryAudio::STATE_CANCELLED, AiCommonTemporaryAudio::STATE_EXPIRED], true)) {
                $locked->update([
                    'state' => AiCommonTemporaryAudio::STATE_CANCELLED,
                    'version' => $locked->version + 1,
                    'draft_text' => null,
                ]);
            }
        }, 3);

        return $this->cleanup($audio->fresh());
    }

    public function cleanupExpired(): int
    {
        $count = 0;
        AiCommonTemporaryAudio::query()
            ->where('expires_at_utc', '<=', now('UTC'))
            ->where('cleanup_status', '!=', AiCommonTemporaryAudio::CLEANUP_COMPLETE)
            ->orderBy('id')->chunkById(100, function ($rows) use (&$count): void {
                foreach ($rows as $audio) {
                    if (! in_array($audio->state, [AiCommonTemporaryAudio::STATE_POSTED, AiCommonTemporaryAudio::STATE_CANCELLED], true)) {
                        $audio->update([
                            'state' => AiCommonTemporaryAudio::STATE_EXPIRED,
                            'version' => $audio->version + 1,
                            'draft_text' => null,
                        ]);
                    }
                    $this->cleanup($audio->fresh());
                    $count++;
                }
            });

        return $count;
    }

    private function authorizeTranscription(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
    ): void {
        $this->authorizeAudio($actor, $organization, $conversation, $audio, true);
        $policy = $this->access->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        if (! $policy->allows_transcription) {
            throw new AuthorizationException;
        }
        if ($audio->expires_at_utc->lte(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['voice' => 'Temporary Audioは期限切れです。']);
        }
    }

    private function authorizeAudio(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonTemporaryAudio $audio,
        bool $requireActive = false,
    ): void {
        $this->access->authorizeConversation($actor, $organization, $conversation, $requireActive);
        if ($audio->organization_id !== $organization->id
            || $audio->ai_common_conversation_id !== $conversation->id
            || $audio->actor_user_id !== $actor->id) {
            throw new AuthorizationException;
        }
    }

    private function existingRecording(
        AiCommonConversation $conversation,
        User $actor,
        string $operationId,
        string $fingerprint,
    ): ?AiCommonTemporaryAudio {
        $existing = AiCommonTemporaryAudio::query()
            ->where('ai_common_conversation_id', $conversation->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation_id', $operationId)->first();
        if ($existing && ! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => '同じ操作IDを異なるVoice Inputには使用できません。']);
        }

        return $existing;
    }

    private function existingTranscription(
        AiCommonTemporaryAudio $audio,
        User $actor,
        string $operationId,
        string $fingerprint,
    ): ?AiCommonTranscriptionOperation {
        $existing = AiCommonTranscriptionOperation::query()
            ->where('ai_common_temporary_audio_id', $audio->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation_id', $operationId)->first();
        if ($existing && ! hash_equals($existing->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => '同じ操作IDを異なる文字起こしには使用できません。']);
        }

        return $existing;
    }

    private function failTranscription(
        AiCommonTemporaryAudio $audio,
        string $operationId,
        string $safeCode,
        bool $unknown,
        ?int $started = null,
    ): void {
        DB::transaction(function () use ($audio, $operationId, $safeCode, $unknown, $started): void {
            $locked = AiCommonTemporaryAudio::query()->lockForUpdate()->findOrFail($audio->id);
            $operation = AiCommonTranscriptionOperation::query()
                ->where('ai_common_temporary_audio_id', $locked->id)
                ->where('operation_id', $operationId)->lockForUpdate()->first();
            $operation?->update([
                'result_status' => $unknown
                    ? AiCommonTranscriptionOperation::RESULT_UNKNOWN
                    : AiCommonTranscriptionOperation::RESULT_FAILED,
                'safe_error_code' => $safeCode,
                'latency_ms' => $started === null ? null : (int) round((hrtime(true) - $started) / 1_000_000),
            ]);
            if ($locked->state === AiCommonTemporaryAudio::STATE_TRANSCRIBING) {
                $locked->update([
                    'state' => $unknown ? AiCommonTemporaryAudio::STATE_UNKNOWN : AiCommonTemporaryAudio::STATE_FAILED,
                    'version' => $locked->version + 1,
                    'safe_error_code' => $safeCode,
                ]);
            }
        }, 3);
    }

    private function cleanup(AiCommonTemporaryAudio $audio): AiCommonTemporaryAudio
    {
        try {
            Storage::disk('ai_common_temporary_audio')->delete($audio->storage_key);
            $audio->update([
                'cleanup_status' => AiCommonTemporaryAudio::CLEANUP_COMPLETE,
                'cleaned_at_utc' => now('UTC'),
            ]);
        } catch (Throwable) {
            $audio->update(['cleanup_status' => AiCommonTemporaryAudio::CLEANUP_PENDING]);
        }

        return $audio->fresh();
    }
}
