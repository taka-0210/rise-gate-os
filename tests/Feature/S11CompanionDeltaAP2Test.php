<?php

namespace Tests\Feature;

use App\Contracts\AiCommonAttachmentInspector;
use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonProvider;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\AiCommonTemporaryAudio;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCommon\AiCommonAttachmentAccess;
use App\Services\AiCommon\AiCommonAttachmentIngestor;
use App\Services\AiCommon\AiCommonTemporaryAudioWriter;
use App\Services\AiCommon\AiCommonTranscriptionException;
use App\Services\AiCommon\FailClosedAiCommonAttachmentInspector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class S11CompanionDeltaAP2Test extends TestCase
{
    use RefreshDatabase;

    private S11DeltaAP2ChatProvider $chat;

    private S11DeltaAP2TranscriptionProvider $transcription;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ai_common_attachments');
        Storage::fake('ai_common_temporary_audio');
        $this->chat = new S11DeltaAP2ChatProvider;
        $this->transcription = new S11DeltaAP2TranscriptionProvider;
        $this->app->instance(AiCommonProvider::class, $this->chat);
        $this->app->instance(AiCommonAttachmentInspector::class, new S11DeltaAP2AttachmentInspector);
        $this->app->instance(AiCommonAudioInspector::class, new S11DeltaAP2AudioInspector);
        $this->app->instance(AiCommonTranscriptionProvider::class, $this->transcription);
    }

    public function test_binary_upload_is_quarantined_inspected_private_and_idempotent(): void
    {
        [$owner, $member, $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $operationId = (string) Str::uuid();
        $file = $this->png('safe.png');
        $ingestor = app(AiCommonAttachmentIngestor::class);

        $attachment = $ingestor->upload($owner, $organization, $conversation, $file, $operationId);
        $replayed = $ingestor->upload($owner, $organization, $conversation, $file, $operationId);

        $this->assertSame($attachment->id, $replayed->id);
        $this->assertSame(AiCommonAttachment::STATE_READY, $attachment->state);
        $this->assertSame('passed', $attachment->inspection_status);
        $this->assertFalse($attachment->allows_ai_reference);
        $this->assertTrue(Storage::disk('ai_common_attachments')->exists($attachment->storage_key));
        $this->assertSame(hash('sha256', Storage::disk('ai_common_attachments')->get($attachment->storage_key)), $attachment->sha256);
        $this->assertStringNotContainsString('safe.png', $attachment->storage_key);
        $this->assertSame(0, $this->chat->calls);

        $session = $this->companySession($organization);
        $this->actingAs($owner)->withSession($session)
            ->get(route('ai-common.attachments.download', [$conversation, $attachment]))
            ->assertOk()->assertHeader('cache-control', 'max-age=0, no-store, private');
        $this->actingAs($member)->withSession($session)
            ->get(route('ai-common.attachments.download', [$conversation, $attachment]))
            ->assertForbidden();
    }

    public function test_spoof_and_unavailable_inspection_never_become_ready(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $ingestor = app(AiCommonAttachmentIngestor::class);

        $spoof = $ingestor->upload(
            $owner,
            $organization,
            $conversation,
            $this->png('spoof.pdf'),
            (string) Str::uuid(),
        );
        $this->assertSame(AiCommonAttachment::STATE_REJECTED, $spoof->state);
        $this->assertSame('rejected', $spoof->inspection_status);
        $this->assertNull($spoof->ready_at_utc);

        $this->app->instance(AiCommonAttachmentInspector::class, new FailClosedAiCommonAttachmentInspector);
        $attachment = app(AiCommonAttachmentIngestor::class)->upload(
            $owner,
            $organization,
            $conversation,
            $this->png('quarantine.png'),
            (string) Str::uuid(),
        );
        $this->assertSame(AiCommonAttachment::STATE_QUARANTINE, $attachment->state);
        $this->assertSame('unavailable', $attachment->inspection_status);
        $this->assertNull($attachment->ready_at_utc);
    }

    public function test_quarantined_upload_cannot_be_overwritten_by_same_operation(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $operationId = (string) Str::uuid();
        $this->app->instance(AiCommonAttachmentInspector::class, new FailClosedAiCommonAttachmentInspector);
        $ingestor = app(AiCommonAttachmentIngestor::class);
        $attachment = $ingestor->upload(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('retry.png', 'first'),
            $operationId,
        );
        $this->assertSame('first', Storage::disk('ai_common_attachments')->get($attachment->storage_key));

        try {
            $ingestor->upload(
                $owner,
                $organization,
                $conversation,
                UploadedFile::fake()->createWithContent('retry.png', 'other'),
                $operationId,
            );
            $this->fail('A quarantined upload was overwritten by another payload.');
        } catch (ValidationException) {
            $this->assertSame('first', Storage::disk('ai_common_attachments')->get($attachment->storage_key));
            $this->assertSame(AiCommonAttachment::STATE_QUARANTINE, $attachment->fresh()->state);
        }
    }

    public function test_ready_state_without_passed_inspection_is_not_usable(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = app(AiCommonAttachmentIngestor::class)->upload(
            $owner,
            $organization,
            $conversation,
            $this->png('legacy-ready.png'),
            (string) Str::uuid(),
        );
        $attachment->update(['inspection_status' => 'pending']);

        $this->expectException(ValidationException::class);
        app(AiCommonAttachmentAccess::class)->authorizeAttachment(
            $owner,
            $organization,
            $conversation,
            $attachment->fresh(),
            true,
        );
    }

    public function test_storage_tamper_and_revoke_fail_closed(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = app(AiCommonAttachmentIngestor::class)->upload(
            $owner,
            $organization,
            $conversation,
            $this->png('private.png'),
            (string) Str::uuid(),
        );
        Storage::disk('ai_common_attachments')->put($attachment->storage_key, 'tampered');

        $this->expectException(ValidationException::class);
        app(AiCommonAttachmentAccess::class)->authorizeAttachment(
            $owner,
            $organization,
            $conversation,
            $attachment,
            true,
        );
    }

    public function test_voice_requires_explicit_transcription_then_human_post_and_cleans_up(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $audio = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('voice.webm', 'synthetic-voice-binary'),
            (string) Str::uuid(),
        );

        $this->assertSame(AiCommonTemporaryAudio::STATE_RECORDED, $audio->state);
        $this->assertSame('UTC', $audio->expires_at_utc->timezoneName);
        $this->assertTrue($audio->expires_at_utc->between(now('UTC')->addMinutes(59), now('UTC')->addMinutes(61)));
        $this->assertStringNotContainsString('synthetic-voice-binary', Storage::disk('ai_common_temporary_audio')->get($audio->storage_key));
        $this->assertDatabaseCount('ai_common_messages', 0);
        $this->assertSame(0, $this->chat->calls);
        $this->assertSame(0, $this->transcription->calls);

        $audio = $writer->transcribe(
            $owner,
            $organization,
            $conversation,
            $audio,
            (string) Str::uuid(),
            true,
        );
        $this->assertSame(AiCommonTemporaryAudio::STATE_DRAFT, $audio->state);
        $this->assertSame('Synthetic transcript draft', $audio->draft_text);
        $this->assertDatabaseCount('ai_common_messages', 0);
        $this->assertSame(1, $this->transcription->calls);
        $this->assertSame(0, $this->chat->calls);

        $postOperationId = (string) Str::uuid();
        $message = $writer->postDraft(
            $owner,
            $organization,
            $conversation,
            $audio,
            $postOperationId,
            'Human edited and confirmed transcript',
        );
        $conversation->update(['status' => AiCommonConversation::STATUS_ARCHIVED, 'archived_at' => now()]);
        $replayed = $writer->postDraft(
            $owner,
            $organization,
            $conversation->fresh(),
            $audio->fresh(),
            $postOperationId,
            'Human edited and confirmed transcript',
        );
        $this->assertSame($message->id, $replayed->id);
        $this->assertDatabaseCount('ai_common_messages', 1);
        $this->assertSame('Human edited and confirmed transcript', $message->content);
        $this->assertSame(AiCommonTemporaryAudio::STATE_POSTED, $audio->fresh()->state);
        $this->assertSame(AiCommonTemporaryAudio::CLEANUP_COMPLETE, $audio->fresh()->cleanup_status);
        $this->assertFalse(Storage::disk('ai_common_temporary_audio')->exists($audio->storage_key));
        $this->assertSame(0, $this->chat->calls);
    }

    public function test_transcription_policy_consent_and_current_membership_are_rechecked(): void
    {
        [$owner, , $organization] = $this->tenant(false);
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $audio = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('voice.webm', 'policy-voice'),
            (string) Str::uuid(),
        );

        try {
            $writer->transcribe($owner, $organization, $conversation, $audio, (string) Str::uuid(), false);
            $this->fail('Transcription started without consent.');
        } catch (ValidationException) {
            $this->assertSame(0, $this->transcription->calls);
        }
        try {
            $writer->transcribe($owner, $organization, $conversation, $audio, (string) Str::uuid(), true);
            $this->fail('Transcription started while organization policy was off.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $this->transcription->calls);
        }

        OrganizationAiPolicy::query()->where('organization_id', $organization->id)->update(['allows_transcription' => true]);
        $this->transcription->duringCall = function () use ($owner, $organization): void {
            $organization->users()->updateExistingPivot($owner->id, [
                'membership_status' => 'stopped',
                'access_epoch' => 2,
            ]);
        };
        try {
            $writer->transcribe($owner, $organization, $conversation, $audio, (string) Str::uuid(), true);
            $this->fail('A provider result was published after membership loss.');
        } catch (AuthorizationException) {
            $this->assertSame(AiCommonTemporaryAudio::STATE_FAILED, $audio->fresh()->state);
            $this->assertDatabaseHas('ai_common_transcription_operations', [
                'ai_common_temporary_audio_id' => $audio->id,
                'result_status' => 'discarded',
                'safe_error_code' => 'transcription_authorization_changed',
            ]);
            $this->assertDatabaseCount('ai_common_messages', 0);
        }
    }

    public function test_cancel_during_provider_io_discards_late_transcript_and_keeps_cancelled_state(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $audio = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('cancel-inflight.webm', 'cancel-inflight'),
            (string) Str::uuid(),
        );
        $this->transcription->duringCall = function () use ($writer, $owner, $organization, $conversation, $audio): void {
            $writer->cancel($owner, $organization, $conversation, $audio->fresh());
        };

        try {
            $writer->transcribe($owner, $organization, $conversation, $audio, (string) Str::uuid(), true);
            $this->fail('A late transcript was accepted after cancellation.');
        } catch (ValidationException) {
            $this->assertSame(AiCommonTemporaryAudio::STATE_CANCELLED, $audio->fresh()->state);
            $this->assertNull($audio->fresh()->draft_text);
            $this->assertFalse(Storage::disk('ai_common_temporary_audio')->exists($audio->storage_key));
            $this->assertDatabaseCount('ai_common_messages', 0);
        }
    }

    public function test_missing_temporary_audio_fails_without_provider_call_or_stuck_processing_state(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $audio = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('missing.webm', 'missing-audio'),
            (string) Str::uuid(),
        );
        Storage::disk('ai_common_temporary_audio')->delete($audio->storage_key);

        try {
            $writer->transcribe($owner, $organization, $conversation, $audio, (string) Str::uuid(), true);
            $this->fail('A missing temporary audio object was sent to the provider.');
        } catch (ValidationException) {
            $this->assertSame(AiCommonTemporaryAudio::STATE_FAILED, $audio->fresh()->state);
            $this->assertSame('temporary_audio_integrity_failed', $audio->fresh()->safe_error_code);
            $this->assertSame(0, $this->transcription->calls);
        }
    }

    public function test_unknown_provider_result_is_not_blindly_retried(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $audio = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('voice.webm', 'unknown-result'),
            (string) Str::uuid(),
        );
        $this->transcription->error = new AiCommonTranscriptionException('transcription_result_unknown', true);
        $operationId = (string) Str::uuid();

        try {
            $writer->transcribe($owner, $organization, $conversation, $audio, $operationId, true);
            $this->fail('Unknown provider result was accepted.');
        } catch (ValidationException) {
            $this->assertSame(AiCommonTemporaryAudio::STATE_UNKNOWN, $audio->fresh()->state);
            $this->assertSame(1, $this->transcription->calls);
        }
        try {
            $writer->transcribe($owner, $organization, $conversation, $audio->fresh(), $operationId, true);
            $this->fail('Unknown provider result was retried with the same operation.');
        } catch (ValidationException) {
            $this->assertSame(1, $this->transcription->calls);
        }
    }

    public function test_cancel_and_expiry_remove_temporary_binary_without_posting(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $cancelled = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('cancel.webm', 'cancel-voice'),
            (string) Str::uuid(),
        );
        $writer->cancel($owner, $organization, $conversation, $cancelled);
        $this->assertSame(AiCommonTemporaryAudio::STATE_CANCELLED, $cancelled->fresh()->state);
        $this->assertFalse(Storage::disk('ai_common_temporary_audio')->exists($cancelled->storage_key));

        $expired = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('expired.webm', 'expired-voice'),
            (string) Str::uuid(),
        );
        $expired->update(['expires_at_utc' => now('UTC')->subSecond()]);
        $this->assertSame(1, $writer->cleanupExpired());
        $this->assertSame(AiCommonTemporaryAudio::STATE_EXPIRED, $expired->fresh()->state);
        $this->assertFalse(Storage::disk('ai_common_temporary_audio')->exists($expired->storage_key));
        $this->assertDatabaseCount('ai_common_messages', 0);
    }

    public function test_private_ui_keeps_human_attachment_voice_and_ai_actions_separate(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);

        $response = $this->actingAs($owner)->withSession($this->companySession($organization))
            ->get(route('ai-common.show', $conversation));
        $response->assertOk()
            ->assertSee('Human Messageとして保存（AI送信なし）')
            ->assertSee('非公開quarantineへ追加')
            ->assertSee('Temporary Audio → 文字起こし')
            ->assertSee('ai-common-input.js', false)
            ->assertDontSee('autoplay', false);
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='),
        );
    }

    private function conversation(User $owner, Organization $organization): AiCommonConversation
    {
        return AiCommonConversation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Delta A P2 private conversation',
            'status' => AiCommonConversation::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }

    private function companySession(Organization $organization): array
    {
        return [
            'access_mode' => 'workspace',
            'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1,
            'credential_generation' => 1,
        ];
    }

    private function tenant(bool $transcription = false): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Delta A P2 Synthetic',
            'slug' => 'delta-a-p2-'.Str::lower((string) Str::ulid()),
        ]);
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Delta A P2',
            'slug' => 'delta-a-p2-'.Str::lower((string) Str::ulid()),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        foreach ([[$owner, 'owner'], [$member, 'member']] as [$user, $role]) {
            $organization->users()->attach($user->id, [
                'role' => $role,
                'organization_role' => $role,
                'membership_status' => 'active',
                'access_epoch' => 1,
                'lifecycle_version' => 1,
                'joined_at' => now(),
            ]);
            $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
            ProductAccountEligibility::query()->create([
                'user_id' => $user->id,
                'mode' => ProductAccountEligibility::MODE_SINGLE,
                'product_organization_id' => $organization->id,
                'classification_version' => 'delta-a-p2-test',
                'classified_at' => now(),
                'evidence_ref' => 'delta-a-p2-test',
            ]);
        }
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id,
            'is_enabled' => true,
            'allows_transcription' => $transcription,
            'allowed_categories' => ['common_entry'],
            'version' => 1,
            'managed_by_user_id' => $owner->id,
            'confirmed_at' => now(),
        ]);

        return [$owner, $member, $organization, $workspace];
    }
}

class S11DeltaAP2AttachmentInspector implements AiCommonAttachmentInspector
{
    public function inspect(string $binary, array $metadata): array
    {
        if (! str_starts_with($binary, "\x89PNG\r\n\x1a\n") || $metadata['mime_type'] !== 'image/png') {
            throw new \RuntimeException('unsafe_binary');
        }

        return ['driver' => 'synthetic-safe-inspector', 'version' => 'p2-test-v1'];
    }
}

class S11DeltaAP2AudioInspector implements AiCommonAudioInspector
{
    public function inspect(string $binary, string $extension, string $mimeType): array
    {
        return [
            'mime_type' => 'audio/webm',
            'extension' => 'webm',
            'codec' => 'opus',
            'duration_ms' => 60_000,
        ];
    }
}

class S11DeltaAP2TranscriptionProvider implements AiCommonTranscriptionProvider
{
    public int $calls = 0;

    public ?\Closure $duringCall = null;

    public ?AiCommonTranscriptionException $error = null;

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        $this->calls++;
        ($this->duringCall) && ($this->duringCall)();
        if ($this->error) {
            throw $this->error;
        }

        return [
            'text' => 'Synthetic transcript draft',
            'provider' => 'synthetic',
            'model' => 'synthetic-transcription-v1',
        ];
    }
}

class S11DeltaAP2ChatProvider implements AiCommonProvider
{
    public int $calls = 0;

    public function respond(array $messages, array $sources): array
    {
        $this->calls++;

        return [
            'answer' => 'Unexpected chat call',
            'citations' => [],
            'provider' => 'unexpected',
            'model' => 'unexpected',
            'input_tokens' => null,
            'output_tokens' => null,
        ];
    }
}
