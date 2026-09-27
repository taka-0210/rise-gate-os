<?php

namespace App\Services\AiCommon;

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedAudioWindow;
use App\Models\AiCommonSharedCaptureStream;
use App\Models\AiCommonSharedCoState;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\AiCommonSharedTranscriptRevision;
use App\Models\AiCommonSharedTranscriptSegment;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AiCommonSharedSessionAudioWriter
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_DURATION_MS = 60 * 1000;

    private const MAX_SEGMENTS = 100;

    private const EXTENSIONS = ['mp3', 'm4a', 'mp4', 'webm', 'wav'];

    public function __construct(
        private readonly AiCommonSharedSessionAccess $access,
        private readonly AiCommonAccess $common,
        private readonly AiCommonAudioInspector $inspector,
        private readonly AiCommonTranscriptionProvider $provider,
        private readonly AiCommonTranscriptionEvidence $evidence,
        private readonly AiCommonSharedAudioCleanup $cleanup,
        private readonly AiCommonSharedLongContext $longContext,
    ) {}

    public function recordWindow(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        AiCommonSharedCaptureStream $stream,
        UploadedFile $file,
        int $generation,
        int $sequence,
        string $operationId,
    ): AiCommonSharedAudioWindow {
        if (! Str::isUuid($operationId) || ! $file->isValid() || $generation < 1 || $sequence < 1) {
            throw ValidationException::withMessages(['audio' => 'A valid bounded audio window is required.']);
        }
        $sessionParticipant = $this->access->authorize($actor, $organization, $conversation, $session, true);
        $this->access->assertConsents($actor, $organization, $conversation, $session, [
            AiCommonSharedSessionConsent::PURPOSE_RECORDING,
            AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
        ]);
        if ($stream->ai_common_shared_session_id !== $session->id
            || $stream->operator_session_participant_id !== $sessionParticipant->id
            || $stream->generation !== $generation
            || $stream->state !== AiCommonSharedCaptureStream::STATE_RECORDING) {
            throw ValidationException::withMessages(['stream' => 'Old, stopped, or foreign capture generation was rejected.']);
        }
        $binary = file_get_contents($file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        if (! is_string($binary) || $binary === '' || strlen($binary) > self::MAX_BYTES
            || ! in_array($extension, self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['audio' => 'Audio window must be an allowed format within 10 MiB.']);
        }
        $inspection = $this->inspector->inspect($binary, $extension, $mime);
        if ($inspection['duration_ms'] < 1 || $inspection['duration_ms'] > self::MAX_DURATION_MS) {
            throw ValidationException::withMessages(['audio' => 'Each independently decodable audio window must be at most 60 seconds.']);
        }
        $hash = hash('sha256', $binary);
        $fingerprint = hash('sha256', json_encode([
            $stream->id, $generation, $sequence, $hash, strlen($binary), $inspection['mime_type'],
        ], JSON_THROW_ON_ERROR));
        if ($existing = $this->existingWindow($session, $actor, $operationId, $fingerprint)) {
            return $existing;
        }
        $publicId = (string) Str::ulid();
        $storageKey = 'shared-session/'.$organization->public_id.'/'.$session->public_id.'/'.$publicId.'.enc';
        Storage::disk('ai_common_temporary_audio')->put($storageKey, Crypt::encryptString(base64_encode($binary)));
        try {
            $window = DB::transaction(function () use (
                $actor, $organization, $conversation, $session, $stream, $generation, $sequence,
                $operationId, $fingerprint, $publicId, $storageKey, $inspection, $hash, $binary,
            ): AiCommonSharedAudioWindow {
                $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
                $lockedStream = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($stream->id);
                $participant = $this->access->authorize($actor, $organization, $conversation->fresh(), $lockedSession, true);
                $this->access->assertConsents($actor, $organization, $conversation->fresh(), $lockedSession, [
                    AiCommonSharedSessionConsent::PURPOSE_RECORDING,
                    AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
                ]);
                if ($lockedSession->state !== AiCommonSharedSession::STATE_ACTIVE
                    || $lockedSession->hard_stop_at_utc->lte(now())
                    || $lockedStream->ai_common_shared_session_id !== $lockedSession->id
                    || $lockedStream->operator_session_participant_id !== $participant->id
                    || $lockedStream->state !== AiCommonSharedCaptureStream::STATE_RECORDING
                    || $lockedStream->generation !== $generation
                    || $sequence !== $lockedStream->sequence + 1) {
                    throw ValidationException::withMessages(['stream' => 'Audio window sequence or generation is no longer current.']);
                }
                if ($existing = $this->existingWindow($lockedSession, $actor, $operationId, $fingerprint)) {
                    return $existing;
                }
                $window = AiCommonSharedAudioWindow::query()->create([
                    'public_id' => $publicId,
                    'ai_common_shared_session_id' => $lockedSession->id,
                    'ai_common_shared_capture_stream_id' => $lockedStream->id,
                    'actor_user_id' => $actor->id,
                    'operation_id' => $operationId,
                    'payload_fingerprint' => $fingerprint,
                    'generation' => $generation,
                    'sequence' => $sequence,
                    'state' => AiCommonSharedAudioWindow::STATE_RECORDED,
                    'storage_key' => $storageKey,
                    'mime_type' => $inspection['mime_type'],
                    'extension' => $inspection['extension'],
                    'size_bytes' => strlen($binary),
                    'sha256' => $hash,
                    'codec' => $inspection['codec'],
                    'duration_ms' => $inspection['duration_ms'],
                    'expires_at_utc' => now()->addHour(),
                    'cleanup_status' => AiCommonSharedAudioWindow::CLEANUP_PENDING,
                ]);
                $lockedStream->update(['sequence' => $sequence, 'version' => $lockedStream->version + 1]);
                $lockedSession->update(['sequence' => $lockedSession->sequence + 1, 'version' => $lockedSession->version + 1]);

                return $window;
            }, 3);
            if ($window->storage_key !== $storageKey) {
                Storage::disk('ai_common_temporary_audio')->delete($storageKey);
            }

            return $window;
        } catch (Throwable $error) {
            Storage::disk('ai_common_temporary_audio')->delete($storageKey);
            if ($existing = $this->existingWindow($session, $actor, $operationId, $fingerprint)) {
                return $existing;
            }
            throw $error;
        }
    }

    public function transcribe(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        AiCommonSharedAudioWindow $window,
        string $operationId,
    ): AiCommonSharedAudioWindow {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'A valid transcription operation ID is required.']);
        }
        $fingerprint = hash('sha256', $window->sha256.'|'.$window->generation.'|'.$window->sequence.'|transcribe');
        $this->authorizeTranscription($actor, $organization, $conversation, $session, $window);
        if ($window->transcription_operation_id) {
            if ($window->transcription_operation_id !== $operationId
                || ! hash_equals((string) $window->transcription_payload_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['operation_id' => 'Transcription operation was already used.']);
            }
            if ($window->state === AiCommonSharedAudioWindow::STATE_TRANSCRIBED) {
                return $window;
            }
            throw ValidationException::withMessages(['audio' => 'This audio window has a final or in-flight transcription result.']);
        }

        $logicalRequestId = (string) Str::uuid();
        $expectedVersion = DB::transaction(function () use (
            $actor, $organization, $conversation, $session, $window, $operationId,
            $fingerprint, $logicalRequestId,
        ): int {
            $locked = AiCommonSharedAudioWindow::query()->lockForUpdate()->findOrFail($window->id);
            $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
            $this->authorizeTranscription($actor, $organization, $conversation->fresh(), $lockedSession, $locked);
            if ($locked->state !== AiCommonSharedAudioWindow::STATE_RECORDED) {
                throw ValidationException::withMessages(['audio' => 'Only a recorded audio window can be transcribed.']);
            }
            $locked->update([
                'state' => AiCommonSharedAudioWindow::STATE_TRANSCRIBING,
                'version' => $locked->version + 1,
                'transcription_operation_id' => $operationId,
                'transcription_payload_fingerprint' => $fingerprint,
                'logical_request_id' => $logicalRequestId,
                'safe_error_code' => null,
            ]);

            return $locked->version;
        }, 3);
        $this->projectAsr($session, 'provider_processing');

        try {
            $encrypted = Storage::disk('ai_common_temporary_audio')->get($window->storage_key);
            $binary = base64_decode(Crypt::decryptString($encrypted), true);
        } catch (Throwable) {
            $this->finalizeFailure($actor, $organization, $conversation, $window, 'temporary_audio_integrity_failed', false, false);
            $this->projectAsr($session, 'unavailable');
            throw ValidationException::withMessages(['audio' => 'Temporary Session Audio integrity check failed.']);
        }
        if (! is_string($binary) || ! hash_equals($window->sha256, hash('sha256', $binary))) {
            $this->finalizeFailure($actor, $organization, $conversation, $window, 'temporary_audio_integrity_failed', false, false);
            $this->projectAsr($session, 'unavailable');
            throw ValidationException::withMessages(['audio' => 'Temporary Session Audio integrity check failed.']);
        }

        $started = hrtime(true);
        try {
            $this->authorizeTranscription($actor->fresh(), $organization, $conversation->fresh(), $session->fresh(), $window->fresh());
            $result = $this->provider->transcribe($binary, $window->mime_type, $window->extension);
            $segments = $this->normalizeSegments($result, $window);
        } catch (AiCommonTranscriptionException $error) {
            $this->finalizeFailure($actor, $organization, $conversation, $window, $error->safeCode, $error->resultUnknown, true, $started);
            $this->projectAsr($session, 'unavailable');
            throw ValidationException::withMessages(['audio' => $error->safeCode]);
        } catch (AuthorizationException|ValidationException $error) {
            $this->finalizeFailure($actor, $organization, $conversation, $window, 'transcription_authorization_changed', false, true, $started, discarded: true);
            $this->projectAsr($session, 'unavailable');
            throw $error;
        } catch (Throwable $error) {
            $this->finalizeFailure($actor, $organization, $conversation, $window, 'transcription_result_unknown', true, true, $started);
            $this->projectAsr($session, 'unavailable');
            throw new AiCommonTranscriptionException('transcription_result_unknown', true, $error);
        }

        try {
            $published = DB::transaction(function () use (
                $actor, $organization, $conversation, $session, $window, $expectedVersion,
                $result, $segments, $started,
            ): AiCommonSharedAudioWindow {
                $lockedSession = AiCommonSharedSession::query()->lockForUpdate()->findOrFail($session->id);
                $locked = AiCommonSharedAudioWindow::query()->lockForUpdate()->findOrFail($window->id);
                $stream = AiCommonSharedCaptureStream::query()->lockForUpdate()->findOrFail($locked->ai_common_shared_capture_stream_id);
                $this->authorizeTranscription($actor->fresh(), $organization, $conversation->fresh(), $lockedSession, $locked);
                if ($locked->version !== $expectedVersion
                    || $locked->state !== AiCommonSharedAudioWindow::STATE_TRANSCRIBING
                    || ! in_array($lockedSession->state, [AiCommonSharedSession::STATE_ACTIVE, AiCommonSharedSession::STATE_PAUSED], true)
                    || $stream->generation !== $locked->generation
                    || in_array($stream->state, [AiCommonSharedCaptureStream::STATE_CANCELLED, AiCommonSharedCaptureStream::STATE_INTERRUPTED], true)) {
                    throw ValidationException::withMessages(['audio' => 'Late ASR result was fenced after Session or generation change.']);
                }
                foreach ($segments as $index => $segmentData) {
                    $segment = AiCommonSharedTranscriptSegment::query()->create([
                        'ai_common_shared_session_id' => $lockedSession->id,
                        'ai_common_shared_audio_window_id' => $locked->id,
                        'ai_common_shared_capture_stream_id' => $stream->id,
                        'segment_index' => $index + 1,
                        'speaker_label' => $segmentData['speaker'],
                        'speaker_scope' => 'window:'.$locked->public_id.':'.$segmentData['speaker'],
                        'range_start_ms' => $segmentData['start_ms'],
                        'range_end_ms' => $segmentData['end_ms'],
                        'confidence' => $segmentData['confidence'],
                    ]);
                    $revision = AiCommonSharedTranscriptRevision::query()->create([
                        'ai_common_shared_transcript_segment_id' => $segment->id,
                        'created_by_user_id' => null,
                        'revision_no' => 1,
                        'kind' => AiCommonSharedTranscriptRevision::KIND_PROVIDER,
                        'operation_id' => $locked->transcription_operation_id,
                        'payload_fingerprint' => hash('sha256', json_encode($segmentData, JSON_THROW_ON_ERROR)),
                        'content' => $segmentData['text'],
                        'content_sha256' => hash('sha256', $segmentData['text']),
                        'range_start_ms' => $segmentData['start_ms'],
                        'range_end_ms' => $segmentData['end_ms'],
                        'provider' => $result['provider'],
                        'model' => $result['model'] ?? null,
                    ]);
                    $segment->update(['current_revision_id' => $revision->id]);
                }
                $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
                $locked->update([
                    'state' => AiCommonSharedAudioWindow::STATE_TRANSCRIBED,
                    'version' => $locked->version + 1,
                    'provider' => $result['provider'],
                    'model' => $result['model'] ?? null,
                    'result_status' => 'success',
                    'safe_error_code' => null,
                ]);
                $this->evidence->record(
                    $organization,
                    $conversation,
                    $actor,
                    $locked->transcription_operation_id,
                    $locked->logical_request_id,
                    'shared_audio_window',
                    $locked->public_id,
                    $result['provider'],
                    $result['model'] ?? null,
                    'success',
                    null,
                    $latencyMs,
                    $locked->duration_ms,
                    $result['usage'] ?? [],
                );

                return $locked->fresh();
            }, 3);
        } catch (AuthorizationException|ValidationException $error) {
            $this->finalizeFailure($actor, $organization, $conversation, $window, 'late_result_fenced', false, true, $started, $result, true);
            $this->projectAsr($session, 'unavailable');
            throw $error;
        }
        $this->cleanup->cleanup($published);
        $this->projectAsr($session, 'idle');

        foreach ($published->fresh(['segments.currentRevision'])->segments as $segment) {
            if ($segment->currentRevision) {
                $this->longContext->markDirty($segment->currentRevision);
            }
        }

        return $published->fresh(['segments.currentRevision']);
    }

    private function projectAsr(AiCommonSharedSession $session, string $state): void
    {
        $co = AiCommonSharedCoState::query()->where('current_session_id', $session->id)->first();
        if ($co) {
            $co->update(['asr_state' => $state, 'room_sequence' => $co->room_sequence + 1]);
        }
    }

    private function authorizeTranscription(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedSession $session,
        AiCommonSharedAudioWindow $window,
    ): void {
        $this->access->authorize($actor, $organization, $conversation, $session, true);
        $this->access->assertConsents($actor, $organization, $conversation, $session, [
            AiCommonSharedSessionConsent::PURPOSE_RECORDING,
            AiCommonSharedSessionConsent::PURPOSE_EXTERNAL_ASR,
            AiCommonSharedSessionConsent::PURPOSE_TRANSCRIPT_SHARING,
        ]);
        $policy = $this->common->authorizeCategory($actor, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
        if (! $policy->allows_transcription
            || $window->ai_common_shared_session_id !== $session->id
            || $window->expires_at_utc->lte(now())) {
            throw new AuthorizationException;
        }
    }

    private function normalizeSegments(array $result, AiCommonSharedAudioWindow $window): array
    {
        $raw = $result['segments'] ?? null;
        if ($raw === null) {
            $text = trim((string) ($result['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 4000) {
                throw new AiCommonTranscriptionException('transcription_invalid_response');
            }

            return [[
                'speaker' => AiCommonSharedTranscriptSegment::SPEAKER_UNKNOWN,
                'start_ms' => 0,
                'end_ms' => $window->duration_ms,
                'text' => $text,
                'confidence' => null,
            ]];
        }
        if (! is_array($raw) || $raw === [] || count($raw) > self::MAX_SEGMENTS) {
            throw new AiCommonTranscriptionException('diarization_invalid_response');
        }
        $segments = [];
        foreach ($raw as $item) {
            $label = trim((string) ($item['speaker'] ?? ''));
            $text = trim((string) ($item['text'] ?? ''));
            $start = filter_var($item['start_ms'] ?? null, FILTER_VALIDATE_INT);
            $end = filter_var($item['end_ms'] ?? null, FILTER_VALIDATE_INT);
            if ($label === '' || ! preg_match('/^(?:Speaker [A-Z0-9]{1,3}|Unknown)$/', $label)
                || $text === '' || mb_strlen($text) > 4000 || $start === false || $end === false
                || $start < 0 || $end <= $start || $end > $window->duration_ms) {
                throw new AiCommonTranscriptionException('diarization_invalid_response');
            }
            $confidence = isset($item['confidence']) && is_numeric($item['confidence'])
                ? max(0, min(1, (float) $item['confidence']))
                : null;
            $segments[] = compact('text') + [
                'speaker' => $label, 'start_ms' => $start, 'end_ms' => $end, 'confidence' => $confidence,
            ];
        }

        return $segments;
    }

    private function existingWindow(AiCommonSharedSession $session, User $actor, string $operationId, string $fingerprint): ?AiCommonSharedAudioWindow
    {
        $window = AiCommonSharedAudioWindow::query()
            ->where('ai_common_shared_session_id', $session->id)
            ->where('actor_user_id', $actor->id)
            ->where('operation_id', $operationId)->first();
        if ($window && ! hash_equals($window->payload_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['operation_id' => 'Audio operation was reused with different content.']);
        }

        return $window;
    }

    private function finalizeFailure(
        User $actor,
        Organization $organization,
        AiCommonConversation $conversation,
        AiCommonSharedAudioWindow $window,
        string $safeCode,
        bool $unknown,
        bool $providerAttempted,
        ?int $started = null,
        array $providerResult = [],
        bool $discarded = false,
    ): void {
        DB::transaction(function () use (
            $actor, $organization, $conversation, $window, $safeCode, $unknown,
            $providerAttempted, $started, $providerResult, $discarded,
        ): void {
            $locked = AiCommonSharedAudioWindow::query()->lockForUpdate()->find($window->id);
            if (! $locked || $locked->state !== AiCommonSharedAudioWindow::STATE_TRANSCRIBING) {
                return;
            }
            $status = $discarded ? 'discarded' : ($unknown ? 'unknown' : 'failed');
            $locked->update([
                'state' => $discarded
                    ? AiCommonSharedAudioWindow::STATE_DISCARDED
                    : ($unknown ? AiCommonSharedAudioWindow::STATE_UNKNOWN : AiCommonSharedAudioWindow::STATE_FAILED),
                'version' => $locked->version + 1,
                'provider' => $providerResult['provider'] ?? 'unknown',
                'model' => $providerResult['model'] ?? null,
                'result_status' => $status,
                'safe_error_code' => $safeCode,
            ]);
            $latency = $started === null ? null : (int) round((hrtime(true) - $started) / 1_000_000);
            $this->evidence->record(
                $organization,
                $conversation,
                $actor,
                $locked->transcription_operation_id,
                $locked->logical_request_id,
                'shared_audio_window',
                $locked->public_id,
                $providerResult['provider'] ?? 'unknown',
                $providerResult['model'] ?? null,
                $status,
                $safeCode,
                $latency,
                $locked->duration_ms,
                $providerResult['usage'] ?? [],
                $providerAttempted,
            );
        }, 3);
        if ($discarded) {
            $this->cleanup->cleanup($window->fresh());
        }
    }
}
