<?php

namespace Tests\Feature;

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonProvider;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonSharedAudioWindow;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedAudioCleanup;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedSessionAudioWriter;
use App\Services\AiCommon\AiCommonSharedSessionWriter;
use App\Services\AiCommon\AiCommonSharedTranscriptWriter;
use App\Services\AiCommon\AiCommonTranscriptionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class S11CompanionDeltaBP3Test extends TestCase
{
    use RefreshDatabase;

    private Bp3TranscriptionProvider $provider;

    private Bp3AudioInspector $inspector;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ai_common_temporary_audio');
        $this->provider = new Bp3TranscriptionProvider;
        $this->inspector = new Bp3AudioInspector;
        $this->app->instance(AiCommonAudioInspector::class, $this->inspector);
        $this->app->instance(AiCommonTranscriptionProvider::class, $this->provider);
        $this->app->instance(AiCommonProvider::class, new Bp3ChatProvider);
    }

    public function test_session_lifecycle_requires_explicit_separate_consent_and_one_stream(): void
    {
        [$owner, $member, $organization, $conversation] = $this->fixture();
        $writer = app(AiCommonSharedSessionWriter::class);
        $session = $writer->prepare($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'mode' => 'shared_room']);
        $this->assertSame($conversation->sharedConversation->current_purpose_revision_id, $session->purpose_revision_id);
        $this->assertDatabaseCount('ai_common_shared_session_participants', 2);
        try {
            $writer->activate($owner, $organization, $conversation, $session);
            $this->fail('Session started without roster consent.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->consent($writer, $owner, $organization, $conversation, $session);
        $this->consent($writer, $member, $organization, $conversation, $session);
        $this->assertDatabaseCount('ai_common_shared_session_consents', 8);
        $session = $writer->activate($owner, $organization, $conversation, $session);
        $stream = $writer->startStream($owner, $organization, $conversation, $session, $this->streamInput());
        $this->assertSame(1, $stream->generation);
        try {
            $writer->startStream($member, $organization, $conversation, $session, $this->streamInput());
            $this->fail('A second active stream was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        try {
            $writer->prepare($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'mode' => 'distributed_multi_mic']);
            $this->fail('An unsupported mode was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $writer->stopStream($owner, $organization, $conversation, $session, $stream);
        $session = $writer->pause($owner, $organization, $conversation, $session);
        $this->assertSame('paused', $session->state);
        $session = $writer->resume($owner, $organization, $conversation, $session);
        $this->assertSame('active', $session->state);
        $session = $writer->end($owner, $organization, $conversation, $session);
        $this->assertSame('ended', $session->state);
        $this->assertNotNull($session->ended_at_utc);
    }

    public function test_bounded_window_creates_multi_speaker_transcript_and_cleans_raw_audio(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->provider->segments = [
            ['speaker' => 'Speaker A', 'text' => 'First speaker', 'start_ms' => 0, 'end_ms' => 12000, 'confidence' => .92],
            ['speaker' => 'Speaker B', 'text' => 'Second speaker', 'start_ms' => 12000, 'end_ms' => 30000, 'confidence' => .87],
        ];
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $window = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio(), 1, 1, (string) Str::uuid());
        Storage::disk('ai_common_temporary_audio')->assertExists($window->storage_key);
        $window = $audio->transcribe($owner, $organization, $conversation, $session, $window, (string) Str::uuid());
        $this->assertSame(AiCommonSharedAudioWindow::STATE_TRANSCRIBED, $window->state);
        $this->assertSame(['Speaker A', 'Speaker B'], $window->segments->pluck('speaker_label')->all());
        $this->assertNotSame($window->segments[0]->speaker_scope, $window->segments[1]->speaker_scope);
        $this->assertDatabaseCount('ai_common_shared_transcript_revisions', 2);
        $this->assertDatabaseCount('ai_common_messages', 0);
        $this->assertDatabaseCount('ai_common_shared_ai_requests', 0);
        $this->assertDatabaseHas('ai_common_shared_audio_windows', ['id' => $window->id, 'cleanup_status' => 'complete']);
        Storage::disk('ai_common_temporary_audio')->assertMissing($window->storage_key);
    }

    public function test_unknown_fallback_is_explicit_and_cross_window_identity_is_not_inferred(): void
    {
        [$owner, $member, $organization, $conversation, $session, $stream] = $this->activeFixture();
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $first = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio(), 1, 1, (string) Str::uuid());
        $first = $audio->transcribe($owner, $organization, $conversation, $session, $first, (string) Str::uuid());
        $this->assertSame('Unknown', $first->segments->sole()->speaker_label);
        $second = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio('second'), 1, 2, (string) Str::uuid());
        $second = $audio->transcribe($owner, $organization, $conversation, $session, $second, (string) Str::uuid());
        $this->assertDatabaseCount('ai_common_shared_speaker_relations', 0);
        $this->assertDatabaseCount('ai_common_shared_identity_revisions', 0);

        $transcript = app(AiCommonSharedTranscriptWriter::class);
        $segment = $first->segments->sole();
        $oldRevision = $segment->current_revision_id;
        $revision = $transcript->revise($owner, $organization, $conversation, $session, $segment, (string) Str::uuid(), 'Human corrected text');
        $this->assertNotSame($oldRevision, $revision->id);
        $this->assertDatabaseHas('ai_common_shared_transcript_revisions', ['id' => $oldRevision, 'revision_no' => 1]);
        $identity = $transcript->confirmSelfIdentity($member, $organization, $conversation, $session, $segment, (string) Str::uuid());
        $this->assertSame($member->id, $identity->confirmed_user_id);
        $relation = $transcript->relateSpeakers($owner, $organization, $conversation, $session, $segment, $second->segments->sole(), (string) Str::uuid(), 'explicit review evidence');
        $this->assertSame('explicit_human_evidence', $relation->evidence_type);
        $this->assertDatabaseCount('ai_common_shared_speaker_relations', 1);
    }

    public function test_cancel_and_old_generation_events_are_fenced_while_normal_stop_remains_valid(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $old = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio(), 1, 1, (string) Str::uuid());
        app(AiCommonSharedSessionWriter::class)->stopStream($owner, $organization, $conversation, $session, $stream, true);
        $this->assertSame('cancelled', $old->fresh()->state);
        Storage::disk('ai_common_temporary_audio')->assertMissing($old->storage_key);
        try {
            $audio->transcribe($owner, $organization, $conversation, $session, $old->fresh(), (string) Str::uuid());
            $this->fail('A cancelled window was transcribed.');
        } catch (Throwable) {
            $this->assertSame(0, $this->provider->calls);
        }
        $new = app(AiCommonSharedSessionWriter::class)->startStream($owner, $organization, $conversation, $session->fresh(), $this->streamInput());
        $this->assertSame(2, $new->generation);
        try {
            $audio->recordWindow($owner, $organization, $conversation, $session->fresh(), $stream->fresh(), $this->audio('late'), 1, 2, (string) Str::uuid());
            $this->fail('An old generation event created a File window.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_common_shared_audio_windows', 1);
        }
        $normal = $audio->recordWindow($owner, $organization, $conversation, $session->fresh(), $new, $this->audio('normal'), 2, 1, (string) Str::uuid());
        app(AiCommonSharedSessionWriter::class)->stopStream($owner, $organization, $conversation, $session->fresh(), $new);
        $normal = $audio->transcribe($owner, $organization, $conversation, $session->fresh(), $normal, (string) Str::uuid());
        $this->assertSame('transcribed', $normal->state);
    }

    public function test_provider_result_is_discarded_after_membership_loss_and_consent_change_interrupts(): void
    {
        [$owner, $member, $organization, $conversation, $session, $stream] = $this->activeFixture();
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $window = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio(), 1, 1, (string) Str::uuid());
        $this->provider->duringCall = function () use ($organization, $member): void {
            OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $member->id)
                ->update(['membership_status' => OrganizationUser::STATUS_LEFT, 'access_epoch' => 2]);
        };
        try {
            $audio->transcribe($owner, $organization, $conversation, $session, $window, (string) Str::uuid());
            $this->fail('A provider result was published after roster loss.');
        } catch (Throwable) {
            $this->assertSame(1, $this->provider->calls);
            $this->assertDatabaseCount('ai_common_shared_transcript_segments', 0);
            $this->assertContains($window->fresh()->state, ['discarded', 'failed']);
        }

        OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $member->id)
            ->update(['membership_status' => OrganizationUser::STATUS_ACTIVE, 'access_epoch' => 1]);
        app(AiCommonSharedSessionWriter::class)->decideConsent($owner, $organization, $conversation, $session->fresh(), [
            'operation_id' => (string) Str::uuid(),
            'consents' => ['recording' => 'revoked', 'external_asr' => 'granted', 'transcript_sharing' => 'granted', 'ai_reference' => 'granted'],
        ]);
        $this->assertSame('interrupted', $session->fresh()->state);
        $this->assertSame('interrupted', $stream->fresh()->state);
    }

    public function test_failure_unknown_and_expiry_cleanup_remain_fail_closed(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $window = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio(), 1, 1, (string) Str::uuid());
        $this->provider->error = new AiCommonTranscriptionException('provider_timeout_unknown', true);
        try {
            $audio->transcribe($owner, $organization, $conversation, $session, $window, (string) Str::uuid());
            $this->fail('Unknown provider result was treated as success.');
        } catch (ValidationException) {
            $this->assertSame('unknown', $window->fresh()->state);
            $this->assertSame(1, $this->provider->calls);
            $this->assertDatabaseCount('ai_common_shared_transcript_segments', 0);
        }
        $window->fresh()->update(['expires_at_utc' => now('UTC')->subMinute(), 'cleanup_status' => 'pending']);
        $this->assertSame(1, app(AiCommonSharedAudioCleanup::class)->cleanupExpired());
        Storage::disk('ai_common_temporary_audio')->assertMissing($window->storage_key);
    }

    public function test_external_asr_consent_is_separate_and_window_limit_is_fail_closed(): void
    {
        [$owner, $member, $organization, $conversation] = $this->fixture();
        $writer = app(AiCommonSharedSessionWriter::class);
        $session = $writer->prepare($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'mode' => 'shared_room']);
        $writer->decideConsent($owner, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(),
            'consents' => ['recording' => 'granted', 'external_asr' => 'declined', 'transcript_sharing' => 'granted', 'ai_reference' => 'declined'],
        ]);
        $this->consent($writer, $member, $organization, $conversation, $session);
        $session = $writer->activate($owner, $organization, $conversation, $session);
        $stream = $writer->startStream($owner, $organization, $conversation, $session, $this->streamInput());
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $window = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio(), 1, 1, (string) Str::uuid());
        try {
            $audio->transcribe($owner, $organization, $conversation, $session, $window, (string) Str::uuid());
            $this->fail('External ASR ran without every participant consent.');
        } catch (ValidationException) {
            $this->assertSame(0, $this->provider->calls);
        }

        $writer->stopStream($owner, $organization, $conversation, $session, $stream, true);
        $ownerConsent = array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, 'granted');
        $writer->decideConsent($owner, $organization, $conversation, $session->fresh(), ['operation_id' => (string) Str::uuid(), 'consents' => $ownerConsent]);
        $session = $session->fresh();
        $stream = $writer->startStream($owner, $organization, $conversation, $session, $this->streamInput());
        $this->inspector->durationMs = 60_001;
        $this->expectException(ValidationException::class);
        $audio->recordWindow($owner, $organization, $conversation, $session, $stream, $this->audio('too long'), 2, 1, (string) Str::uuid());
    }

    public function test_shared_session_browser_surface_is_accessible_and_exposes_no_p4_capability(): void
    {
        [$owner, , $organization, $conversation] = $this->fixture();
        $session = app(AiCommonSharedSessionWriter::class)->prepare($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'mode' => 'shared_room']);
        $response = $this->actingAs($owner)->withSession([
            'access_mode' => 'workspace', 'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1, 'credential_generation' => 1,
        ])->get(route('ai-common.shared.show', $conversation));
        $response->assertOk()->assertSee('Shared-room Session / Voice')->assertSee($session->state)
            ->assertSee('Recording')->assertSee('External ASR')->assertSee('Transcript Sharing')->assertSee('AI Reference')
            ->assertDontSee('Rolling Context')->assertDontSee('Historical Retrieval')->assertDontSee('Conversation Atmosphere');
    }

    private function activeFixture(): array
    {
        [$owner, $member, $organization, $conversation] = $this->fixture();
        $writer = app(AiCommonSharedSessionWriter::class);
        $session = $writer->prepare($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'mode' => 'shared_room']);
        $this->consent($writer, $owner, $organization, $conversation, $session);
        $this->consent($writer, $member, $organization, $conversation, $session);
        $session = $writer->activate($owner, $organization, $conversation, $session);
        $stream = $writer->startStream($owner, $organization, $conversation, $session, $this->streamInput());

        return [$owner, $member, $organization, $conversation, $session, $stream];
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'B P3 Synthetic', 'slug' => 'b-p3-'.Str::lower((string) Str::ulid())]);
        $this->attach($owner, $organization, 'owner');
        $this->attach($member, $organization, 'member');
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id,
            'is_enabled' => true,
            'allows_transcription' => true,
            'allowed_categories' => ['common_entry'],
            'version' => 1,
            'managed_by_user_id' => $owner->id,
            'confirmed_at' => now(),
        ]);
        $writer = app(AiCommonSharedConversationWriter::class);
        $conversation = $writer->create($owner, $organization, ['operation_id' => (string) Str::uuid(), 'name' => 'P3 Shared', 'purpose' => 'Bounded shared-room voice']);
        $invitation = $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
        $writer->accept($member, $organization, $invitation);

        return [$owner, $member, $organization, $conversation->fresh()];
    }

    private function attach(User $user, Organization $organization, string $role): void
    {
        $organization->users()->attach($user->id, [
            'role' => $role, 'organization_role' => $role,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now(),
        ]);
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
            'classification_version' => 'b-p3-test',
            'classified_at' => now(),
            'evidence_ref' => 'b-p3-test',
        ]);
    }

    private function consent(AiCommonSharedSessionWriter $writer, User $user, Organization $organization, $conversation, $session): void
    {
        $writer->decideConsent($user, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(),
            'consents' => array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, AiCommonSharedSessionConsent::STATUS_GRANTED),
        ]);
    }

    private function streamInput(): array
    {
        return ['operation_id' => (string) Str::uuid(), 'client_instance_id' => (string) Str::uuid(), 'mode' => 'shared_room'];
    }

    private function audio(string $content = 'synthetic independently decodable audio'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('window.webm', $content);
    }
}

class Bp3AudioInspector implements AiCommonAudioInspector
{
    public int $durationMs = 30_000;

    public function inspect(string $binary, string $extension, string $mimeType): array
    {
        return ['mime_type' => 'audio/webm', 'extension' => 'webm', 'codec' => 'opus', 'duration_ms' => $this->durationMs];
    }
}

class Bp3TranscriptionProvider implements AiCommonTranscriptionProvider
{
    public int $calls = 0;

    public ?array $segments = null;

    public ?\Closure $duringCall = null;

    public ?AiCommonTranscriptionException $error = null;

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        $this->calls++;
        ($this->duringCall) && ($this->duringCall)();
        if ($this->error) {
            throw $this->error;
        }

        $result = ['text' => 'Synthetic provider transcript', 'provider' => 'synthetic', 'model' => 'diarization-v1'];
        if ($this->segments !== null) {
            $result['segments'] = $this->segments;
        }

        return $result;
    }
}

class Bp3ChatProvider implements AiCommonProvider
{
    public int $calls = 0;

    public function respond(array $messages, array $sources): array
    {
        $this->calls++;

        return ['answer' => 'unexpected', 'citations' => [], 'provider' => 'unexpected', 'model' => 'unexpected', 'input_tokens' => null, 'output_tokens' => null];
    }
}
