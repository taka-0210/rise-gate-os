<?php

namespace Tests\Feature;

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonProvider;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonSharedContextCheckpoint;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\AiCommonSharedTranscriptChunk;
use App\Models\AiUsageLedger;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedCoWriter;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedLongContext;
use App\Services\AiCommon\AiCommonSharedSessionAudioWriter;
use App\Services\AiCommon\AiCommonSharedSessionWriter;
use App\Services\AiCommon\AiCommonSharedTranscriptWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class S11CompanionDeltaBP5Test extends TestCase
{
    use RefreshDatabase;

    private Bp5ChatProvider $chat;

    private Bp5TranscriptionProvider $transcription;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ai_common_temporary_audio');
        $this->chat = new Bp5ChatProvider;
        $this->transcription = new Bp5TranscriptionProvider;
        $this->app->instance(AiCommonProvider::class, $this->chat);
        $this->app->instance(AiCommonTranscriptionProvider::class, $this->transcription);
        $this->app->instance(AiCommonAudioInspector::class, new Bp5AudioInspector);
    }

    public function test_60_120_180_minute_equivalent_context_is_bounded_retrievable_and_incremental(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $long = app(AiCommonSharedLongContext::class);
        $requestSizes = [];
        $correctedSegment = null;

        for ($minute = 1; $minute <= 180; $minute++) {
            $text = match ($minute) {
                1 => 'NUMERIC_MARK budget is 73149 and must remain retrievable.',
                30 => 'Original deadline is Tuesday and needs correction.',
                61 => 'OPPOSING_MARK Speaker B rejects the first proposal.',
                121 => 'UNRESOLVED_MARK vendor consent remains unresolved.',
                default => sprintf('minute-%03d bounded synthetic discussion.', $minute),
            };
            $speaker = $minute % 2 === 0 ? 'Speaker B' : 'Speaker A';
            $window = $this->transcribe($owner, $organization, $conversation, $session, $stream, $minute, $text, $speaker);
            if ($minute === 30) {
                $correctedSegment = $window->segments()->firstOrFail();
            }

            if (in_array($minute, [60, 120, 180], true)) {
                $checkpoint = $long->maintain($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid());
                $this->assertSame($minute, $checkpoint->dependencies()->count());
                $query = match ($minute) {
                    60 => 'NUMERIC_MARK 73149',
                    120 => 'OPPOSING_MARK rejects',
                    default => 'UNRESOLVED_MARK consent',
                };
                $context = $long->contextForRequest($owner, $organization, $conversation, $session->fresh(), $query);
                $content = $context['messages'][0]['content'];
                $requestSizes[] = mb_strlen($content);
                $this->assertStringContainsString($query === 'NUMERIC_MARK 73149' ? '73149' : explode(' ', $query)[0], $content);
                $this->assertLessThanOrEqual(36_000, mb_strlen($content));
            }
        }

        $this->assertSame(3, $this->chat->count('maintenance'));
        $this->assertSame(0, $this->chat->count('co'));
        $this->assertLessThanOrEqual(14_000, max($this->chat->maintenanceInputCharacters));
        $this->assertLessThanOrEqual(5_000, max($requestSizes) - min($requestSizes));
        $this->assertSame(3, AiUsageLedger::query()->where('purpose', 'context_maintenance')->count());
        $this->assertSame(180, AiUsageLedger::query()->where('purpose', 'transcription')->count());
        $this->assertGreaterThan(3, AiCommonSharedTranscriptChunk::query()->where('ai_common_shared_session_id', $session->id)->count());
        $this->assertTrue(AiCommonSharedTranscriptChunk::query()->where('character_count', '>', 2_000)->doesntExist());

        $calls = count($this->chat->calls);
        $same = $long->maintain($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid());
        $this->assertSame(3, $same->revision_no);
        $this->assertSame($calls, count($this->chat->calls));

        $this->assertNotNull($correctedSegment);
        app(AiCommonSharedTranscriptWriter::class)->revise(
            $owner,
            $organization,
            $conversation,
            $session,
            $correctedSegment,
            (string) Str::uuid(),
            'CORRECTED_MARK deadline is Thursday.',
        );
        $corrected = $long->maintain($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid());
        $this->assertSame(4, $corrected->revision_no);
        $history = collect($long->historical($owner, $organization, $conversation, $session->fresh(), 'CORRECTED_MARK Thursday'));
        $this->assertStringContainsString('CORRECTED_MARK', $history->pluck('content')->implode(' '));
        $this->assertLessThanOrEqual(5, $history->count());
        $this->assertLessThanOrEqual(4_000, mb_strlen($history->pluck('content')->implode('')));
        $this->assertSame(180, $corrected->dependencies()->count());
    }

    public function test_ten_participant_session_uses_complete_audience_and_fails_closed_after_membership_loss(): void
    {
        [$owner, $participants, $organization, $conversation] = $this->fixture(10);
        $writer = app(AiCommonSharedSessionWriter::class);
        $session = $writer->prepare($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(), 'mode' => 'shared_room',
        ]);
        foreach ($participants as $participant) {
            $this->consent($writer, $participant, $organization, $conversation, $session);
        }
        $session = $writer->activate($owner, $organization, $conversation, $session);
        $this->assertSame(10, $session->participants()->count());
        $this->assertDatabaseCount('ai_common_shared_session_consents', 40);

        $long = app(AiCommonSharedLongContext::class);
        $snapshots = collect($participants)->map(fn (User $participant) => $long->snapshot(
            $participant,
            $organization,
            $conversation,
            $session,
            (string) Str::uuid(),
            0,
        ));
        $this->assertSame(1, $snapshots->pluck('session_id')->unique()->count());
        $this->assertSame(1, $snapshots->pluck('state')->unique()->count());
        $this->assertDatabaseCount('ai_common_shared_device_cursors', 10);

        $lost = $participants->last();
        OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $lost->id)
            ->update(['membership_status' => OrganizationUser::STATUS_LEFT, 'access_epoch' => 2]);
        $this->expectException(AuthorizationException::class);
        $long->snapshot($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 0);
    }

    public function test_provider_presence_and_multi_device_result_are_server_authoritative(): void
    {
        [$owner, $participants, $organization, $conversation, $session, $stream] = $this->activeFixture();
        $member = $participants[1];
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Shared request context.', 'Speaker A');
        $long = app(AiCommonSharedLongContext::class);
        $during = [];
        $this->chat->duringCo = function () use (&$during, $long, $owner, $member, $organization, $conversation, $session): void {
            $during[] = $long->snapshot($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 0);
            $during[] = $long->snapshot($member, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 0);
        };
        $operation = (string) Str::uuid();
        $request = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
            'operation_id' => $operation, 'content' => 'Give the bounded answer.', 'source_ids' => [],
        ]);
        $replay = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
            'operation_id' => $operation, 'content' => 'Give the bounded answer.', 'source_ids' => [],
        ]);

        $this->assertSame($request->id, $replay->id);
        $this->assertCount(2, $during);
        foreach ($during as $snapshot) {
            $this->assertSame('provider_processing', $snapshot['presence']);
            $this->assertSame('provider_processing', $snapshot['phase']);
            $this->assertSame($request->id, $snapshot['request_id']);
            $this->assertNull($snapshot['response_id']);
        }
        $a = $long->snapshot($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 0);
        $b = $long->snapshot($member, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 999_999);
        foreach (['session_id', 'request_id', 'response_id', 'state', 'phase', 'sequence'] as $field) {
            $this->assertSame($a[$field], $b[$field]);
        }
        $this->assertSame('answer_ready', $a['presence']);
        $this->assertSame('published', $a['phase']);
        $this->assertTrue($b['resync_required']);
        $this->assertSame(1, $this->chat->count('co'));
        $this->assertSame(1, AiUsageLedger::query()->where('purpose', 'shared_co_request')->count());
    }

    public function test_end_fences_late_asr_and_session_boundaries_preserve_history(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $window = $audio->recordWindow(
            $owner,
            $organization,
            $conversation,
            $session,
            $stream,
            UploadedFile::fake()->createWithContent('late.webm', 'late-audio'),
            $stream->generation,
            1,
            (string) Str::uuid(),
        );
        $ended = app(AiCommonSharedSessionWriter::class)->end($owner, $organization, $conversation, $session);
        $this->assertSame('ended', $ended->state);
        $this->assertSame('stopped', $stream->fresh()->state);
        try {
            $audio->transcribe($owner, $organization, $conversation, $ended, $window, (string) Str::uuid());
            $this->fail('Late ASR after Session End was published.');
        } catch (Throwable) {
            $this->assertDatabaseCount('ai_common_shared_transcript_segments', 0);
            $this->assertNotSame('transcribed', $window->fresh()->state);
        }
        $this->assertSame(180.0, $ended->created_at->diffInMinutes($ended->hard_stop_at_utc));
        $this->assertDatabaseCount('ai_common_messages', 0);
        $this->assertDatabaseCount('ai_common_shared_ai_requests', 0);
    }

    public function test_browser_contract_exposes_truthful_fallback_and_server_rejects_non_ver1_capture(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Browser state projection.', 'Speaker A');
        $web = $this->actingAs($owner)->withSession([
            'access_mode' => 'workspace', 'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1, 'credential_generation' => 1,
        ]);
        $web->get(route('ai-common.shared.show', $conversation))->assertOk()
            ->assertSee('One Shared CO / Conversation Atmosphere')
            ->assertSee('Context watermark')
            ->assertSee('Shared-room Session / Voice')
            ->assertSee('Recorder stopped')
            ->assertDontSee('考えています');
        $web->getJson(route('ai-common.shared.sessions.snapshot', [$conversation, $session]).'?client_instance_id='.Str::uuid().'&cursor=0')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('capture', 'recording')->assertJsonPath('presence', 'ready');

        $writer = app(AiCommonSharedSessionWriter::class);
        try {
            $writer->startStream($owner, $organization, $conversation, $session->fresh(), [
                'operation_id' => (string) Str::uuid(), 'client_instance_id' => (string) Str::uuid(), 'mode' => 'shared_room',
            ]);
            $this->fail('A second capture stream was accepted.');
        } catch (ValidationException) {
            $this->assertSame('recording', $stream->fresh()->state);
        }
        try {
            $writer->prepare($owner, $organization, $conversation, [
                'operation_id' => (string) Str::uuid(), 'mode' => 'distributed_multi_mic',
            ]);
            $this->fail('An unsupported capture mode was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_common_shared_sessions', 1);
        }
    }

    public function test_foreground_snapshot_reconciles_current_client_capture_and_rechecks_authorization(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $writer = app(AiCommonSharedSessionWriter::class);
        $context = app(AiCommonSharedLongContext::class);
        $clientId = $stream->client_instance_id;

        $recording = $context->snapshot($owner, $organization, $conversation, $session->fresh(), $clientId, 0);
        $this->assertSame('active', $recording['session_state']);
        $this->assertSame('recording', $recording['client_capture']['state']);
        $this->assertSame($stream->public_id, $recording['client_capture']['stream_id']);
        $this->assertSame($stream->generation, $recording['client_capture']['generation']);
        $this->assertNull($context->snapshot($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 0)['client_capture']);

        $session = $writer->pause($owner, $organization, $conversation, $session->fresh());
        $paused = $context->snapshot($owner, $organization, $conversation, $session->fresh(), $clientId, $recording['sequence']);
        $this->assertSame('paused', $paused['session_state']);
        $this->assertSame('paused', $paused['client_capture']['state']);

        $session = $writer->resume($owner, $organization, $conversation, $session->fresh());
        $resumed = $context->snapshot($owner, $organization, $conversation, $session->fresh(), $clientId, $paused['sequence']);
        $this->assertSame('active', $resumed['session_state']);
        $this->assertSame('recording', $resumed['client_capture']['state']);

        $writer->stopStream($owner, $organization, $conversation, $session, $stream, true);
        $cancelled = $context->snapshot($owner, $organization, $conversation, $session->fresh(), $clientId, $resumed['sequence']);
        $this->assertSame('cancelled', $cancelled['client_capture']['state']);
        $this->assertSame('inactive', $cancelled['capture']);

        $next = $writer->startStream($owner, $organization, $conversation, $session->fresh(), [
            'operation_id' => (string) Str::uuid(), 'client_instance_id' => $clientId, 'mode' => 'shared_room',
        ]);
        $this->assertGreaterThan($stream->generation, $next->generation);
        $this->assertSame('recording', $context->snapshot($owner, $organization, $conversation, $session->fresh(), $clientId, 0)['client_capture']['state']);
        $writer->stopStream($owner, $organization, $conversation, $session, $next, true);

        $web = $this->actingAs($owner)->withSession([
            'access_mode' => 'workspace', 'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1, 'credential_generation' => 1,
        ]);
        $web->get(route('ai-common.shared.show', $conversation))->assertOk()
            ->assertSee('data-snapshot-url=', false)
            ->assertSee(route('ai-common.shared.sessions.snapshot', [$conversation, $session]), false);

        OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $owner->id)
            ->update(['membership_status' => OrganizationUser::STATUS_SUSPENDED, 'access_epoch' => 2]);
        $this->expectException(AuthorizationException::class);
        $context->snapshot($owner->fresh(), $organization, $conversation->fresh(), $session->fresh(), $clientId, 0);
    }

    private function activeFixture(int $participantCount = 2): array
    {
        [$owner, $participants, $organization, $conversation] = $this->fixture($participantCount);
        $writer = app(AiCommonSharedSessionWriter::class);
        $session = $writer->prepare($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(), 'mode' => 'shared_room',
        ]);
        foreach ($participants as $participant) {
            $this->consent($writer, $participant, $organization, $conversation, $session);
        }
        $session = $writer->activate($owner, $organization, $conversation, $session);
        $stream = $writer->startStream($owner, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(), 'client_instance_id' => (string) Str::uuid(), 'mode' => 'shared_room',
        ]);

        return [$owner, $participants, $organization, $conversation->fresh(), $session, $stream];
    }

    private function fixture(int $participantCount = 2): array
    {
        $participants = collect(range(1, $participantCount))->map(fn (int $index) => User::factory()->create([
            'name' => 'P5 Participant '.$index,
        ]));
        $owner = $participants->first();
        $organization = Organization::query()->create([
            'name' => 'B P5 Synthetic', 'slug' => 'b-p5-'.Str::lower((string) Str::ulid()),
        ]);
        foreach ($participants as $index => $participant) {
            $this->attach($participant, $organization, $index === 0 ? 'owner' : 'member');
        }
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id, 'is_enabled' => true, 'allows_transcription' => true,
            'allowed_categories' => ['common_entry'], 'version' => 1,
            'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        $writer = app(AiCommonSharedConversationWriter::class);
        $conversation = $writer->create($owner, $organization, [
            'operation_id' => (string) Str::uuid(), 'name' => 'P5 Shared', 'purpose' => 'Integrated bounded close verification',
        ]);
        foreach ($participants->skip(1) as $participant) {
            $invitation = $writer->invite($owner, $organization, $conversation, $participant, ['operation_id' => (string) Str::uuid()]);
            $writer->accept($participant, $organization, $invitation);
        }

        return [$owner, $participants->values(), $organization, $conversation->fresh()];
    }

    private function transcribe(User $owner, Organization $organization, $conversation, $session, $stream, int $sequence, string $text, string $speaker)
    {
        $this->transcription->segments = [[
            'speaker' => $speaker,
            'text' => $text,
            'start_ms' => 0,
            'end_ms' => 60_000,
            'confidence' => 0.91,
        ]];
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $window = $audio->recordWindow(
            $owner,
            $organization,
            $conversation,
            $session,
            $stream,
            UploadedFile::fake()->createWithContent('minute-'.$sequence.'.webm', 'audio-'.$sequence),
            $stream->generation,
            $sequence,
            (string) Str::uuid(),
        );

        return $audio->transcribe($owner, $organization, $conversation, $session, $window, (string) Str::uuid());
    }

    private function consent(AiCommonSharedSessionWriter $writer, User $user, Organization $organization, $conversation, $session): void
    {
        $writer->decideConsent($user, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(),
            'consents' => array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, AiCommonSharedSessionConsent::STATUS_GRANTED),
        ]);
    }

    private function attach(User $user, Organization $organization, string $role): void
    {
        $organization->users()->attach($user->id, [
            'role' => $role, 'organization_role' => $role,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now(),
        ]);
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id, 'classification_version' => 'b-p5-test',
            'classified_at' => now(), 'evidence_ref' => 'b-p5-test',
        ]);
    }
}

class Bp5AudioInspector implements AiCommonAudioInspector
{
    public function inspect(string $binary, string $extension, string $mimeType): array
    {
        return ['mime_type' => 'audio/webm', 'extension' => 'webm', 'codec' => 'opus', 'duration_ms' => 60_000];
    }
}

class Bp5TranscriptionProvider implements AiCommonTranscriptionProvider
{
    public array $segments = [];

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        return [
            'text' => collect($this->segments)->pluck('text')->implode(' '),
            'segments' => $this->segments,
            'provider' => 'synthetic',
            'model' => 'bounded-diarization-p5',
            'usage' => ['unit' => 'duration_seconds', 'quantity' => '60', 'estimated_cost_microunits' => null],
        ];
    }
}

class Bp5ChatProvider implements AiCommonProvider
{
    public array $calls = [];

    public array $maintenanceInputCharacters = [];

    public ?\Closure $duringCo = null;

    public function respond(array $messages, array $sources): array
    {
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode(' ');
        $type = str_contains($system, 'Maintain bounded rolling context') ? 'maintenance'
            : (str_contains($system, 'bounded candidate lines') ? 'organize' : 'co');
        $this->calls[] = ['type' => $type, 'messages' => $messages, 'sources' => $sources];
        if ($type === 'maintenance') {
            $this->maintenanceInputCharacters[] = collect($messages)->sum(fn (array $message): int => mb_strlen((string) $message['content']));
        }
        if ($type === 'co' && $this->duringCo) {
            ($this->duringCo)();
        }
        $answer = match ($type) {
            'maintenance' => json_encode([
                'current_topic' => ['bounded shared session'],
                'main_views' => ['Speaker A supports the proposal', 'Speaker B opposes the proposal'],
                'agreement_candidates' => [],
                'open_questions' => ['UNRESOLVED_MARK vendor consent'],
                'to_confirm' => ['numeric 73149'],
                'source_refs' => [],
            ], JSON_THROW_ON_ERROR),
            'organize' => "decision: Review the bounded candidate\nunresolved: Verify consent\naction_candidate: Use the existing Proposal Engine",
            default => 'Shared CO bounded answer',
        };

        return [
            'answer' => $answer,
            'citations' => [],
            'provider' => 'synthetic',
            'model' => 'p5-integrated-v1',
            'input_tokens' => 140,
            'output_tokens' => 35,
        ];
    }

    public function count(string $type): int
    {
        return collect($this->calls)->where('type', $type)->count();
    }
}
