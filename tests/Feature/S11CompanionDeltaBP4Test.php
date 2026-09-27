<?php

namespace Tests\Feature;

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonProvider;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\AiUsageLedger;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonHumanMessageWriter;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedCoWriter;
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

class S11CompanionDeltaBP4Test extends TestCase
{
    use RefreshDatabase;

    private Bp4ChatProvider $provider;

    private Bp4TranscriptionProvider $transcription;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ai_common_temporary_audio');
        $this->provider = new Bp4ChatProvider;
        $this->transcription = new Bp4TranscriptionProvider;
        $this->app->instance(AiCommonProvider::class, $this->provider);
        $this->app->instance(AiCommonTranscriptionProvider::class, $this->transcription);
        $this->app->instance(AiCommonAudioInspector::class, new Bp4AudioInspector);
    }

    public function test_incremental_checkpoint_coalesces_dirty_transcript_and_no_change_calls_provider_zero_times(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'The first topic budget is 1250.');
        $context = app(AiCommonSharedLongContext::class);
        $first = $context->maintain($owner, $organization, $conversation, $session, (string) Str::uuid());

        $this->assertSame(1, $first->revision_no);
        $this->assertSame(1, $this->provider->count('maintenance'));
        $same = $context->maintain($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid());
        $this->assertSame($first->id, $same->id);
        $this->assertSame(1, $this->provider->count('maintenance'));

        $this->transcribe($owner, $organization, $conversation, $session, $stream, 2, 'Speaker B opposes the schedule.');
        $second = $context->maintain($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid());
        $this->assertSame(2, $second->revision_no);
        $this->assertSame(2, $this->provider->count('maintenance'));
        $this->assertSame('stale', $first->fresh()->status);
        $this->assertDatabaseCount('ai_common_shared_checkpoint_dependencies', 3);
        $this->assertSame(2, AiUsageLedger::query()->where('purpose', 'context_maintenance')->count());
    }

    public function test_transcript_and_identity_revision_make_old_checkpoint_stale_and_preserve_provenance(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $window = $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Original uncertain statement.');
        $context = app(AiCommonSharedLongContext::class);
        $old = $context->maintain($owner, $organization, $conversation, $session, (string) Str::uuid());
        $segment = $window->segments()->firstOrFail();
        app(AiCommonSharedTranscriptWriter::class)->revise($owner, $organization, $conversation, $session, $segment, (string) Str::uuid(), 'Corrected statement with evidence.');

        try {
            $context->authorizeCheckpoint($owner, $organization, $conversation, $session->fresh(), $old->fresh());
            $this->fail('A corrected Transcript must stale the old checkpoint.');
        } catch (ValidationException) {
            $this->assertSame('stale', $old->fresh()->status);
        }
        $corrected = $context->maintain($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid());
        $identity = app(AiCommonSharedTranscriptWriter::class)->confirmSelfIdentity($owner, $organization, $conversation, $session, $segment, (string) Str::uuid());
        try {
            $context->authorizeCheckpoint($owner, $organization, $conversation, $session->fresh(), $corrected->fresh());
            $this->fail('An Identity Revision must stale the old checkpoint.');
        } catch (ValidationException) {
            $this->assertSame('stale', $corrected->fresh()->status);
        }
        $identified = $context->maintain($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid());
        $this->assertSame($identity->id, $identified->dependencies()->where('transcript_segment_id', $segment->id)->value('identity_revision_id'));
    }

    public function test_bounded_historical_retrieval_recovers_numeric_fact_and_opposing_view(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Initial numeric value is 98765 and Speaker A supports it.');
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 2, 'Speaker B opposing view says 98765 is too high.');
        $context = app(AiCommonSharedLongContext::class);
        $context->maintain($owner, $organization, $conversation, $session, (string) Str::uuid());
        $results = $context->historical($owner, $organization, $conversation, $session, '98765 opposing view');

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('98765', collect($results)->pluck('content')->implode(' '));
        $this->assertLessThanOrEqual(5, count($results));
        $this->assertLessThanOrEqual(4000, mb_strlen(collect($results)->pluck('content')->implode('')));
    }

    public function test_only_explicit_co_request_invokes_provider_and_usage_is_split_by_purpose(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Wake phrase CO please listen but do not run.');
        app(AiCommonHumanMessageWriter::class)->postShared($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(), 'content' => 'CO wake phrase only',
        ]);
        $this->assertSame(0, count($this->provider->calls));

        $operation = (string) Str::uuid();
        $first = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
            'operation_id' => $operation, 'content' => 'What is the current topic?', 'source_ids' => [],
        ]);
        $second = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
            'operation_id' => $operation, 'content' => 'What is the current topic?', 'source_ids' => [],
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, $this->provider->count('maintenance'));
        $this->assertSame(1, $this->provider->count('co'));
        $this->assertSame(1, AiUsageLedger::query()->where('purpose', 'context_maintenance')->count());
        $this->assertSame(1, AiUsageLedger::query()->where('purpose', 'shared_co_request')->count());
        $this->assertSame(1, AiUsageLedger::query()->where('purpose', 'transcription')->count());
    }

    public function test_current_participant_consent_and_policy_are_rechecked_for_derived_context(): void
    {
        [$owner, $member, $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Private shared transcript.');
        $context = app(AiCommonSharedLongContext::class);
        $checkpoint = $context->maintain($owner, $organization, $conversation, $session, (string) Str::uuid());
        app(AiCommonSharedSessionWriter::class)->decideConsent($member, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(),
            'consents' => array_merge(array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, 'granted'), ['ai_reference' => 'revoked']),
        ]);

        try {
            $context->authorizeCheckpoint($owner, $organization, $conversation, $session->fresh(), $checkpoint->fresh());
            $this->fail('AI Reference consent loss must fail closed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        app(AiCommonSharedSessionWriter::class)->decideConsent($member, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(),
            'consents' => array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, 'granted'),
        ]);
        OrganizationAiPolicy::query()->where('organization_id', $organization->id)->update(['is_enabled' => false, 'version' => 2]);
        $this->expectException(AuthorizationException::class);
        $context->authorizeCheckpoint($owner, $organization, $conversation, $session->fresh(), $checkpoint->fresh());
    }

    public function test_multi_device_snapshot_is_server_authoritative_and_cursor_gap_resyncs(): void
    {
        [$owner, $member, $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Multi device state.');
        $context = app(AiCommonSharedLongContext::class);
        $context->maintain($owner, $organization, $conversation, $session, (string) Str::uuid());
        $a = $context->snapshot($owner, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 0);
        $b = $context->snapshot($member, $organization, $conversation, $session->fresh(), (string) Str::uuid(), 9999);

        $this->assertSame($a['session_id'], $b['session_id']);
        $this->assertSame($a['context_watermark_segment_id'], $b['context_watermark_segment_id']);
        $this->assertTrue($b['resync_required']);
        $this->assertDatabaseCount('ai_common_shared_device_cursors', 2);
        $this->assertSame(1, $this->provider->count('maintenance'));
    }

    public function test_session_end_alone_calls_no_provider_and_explicit_organization_has_provenance(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Decision candidate: verify the bounded release.');
        $context = app(AiCommonSharedLongContext::class);
        $context->maintain($owner, $organization, $conversation, $session, (string) Str::uuid());
        $before = count($this->provider->calls);
        $session = app(AiCommonSharedSessionWriter::class)->end($owner, $organization, $conversation, $session);
        $this->assertSame($before, count($this->provider->calls));

        $operation = (string) Str::uuid();
        $run = $context->organize($owner, $organization, $conversation, $session, $operation);
        $again = $context->organize($owner, $organization, $conversation, $session, $operation);
        $this->assertSame($run->id, $again->id);
        $this->assertGreaterThan(0, $run->candidates->count());
        $this->assertStringContainsString('dependencies', $run->candidates->first()->provenance);
        $this->assertSame($before + 1, count($this->provider->calls));
    }

    public function test_browser_surface_exposes_truthful_text_fallback_and_no_store_snapshot(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $this->transcribe($owner, $organization, $conversation, $session, $stream, 1, 'Browser projection.');
        app(AiCommonSharedLongContext::class)->maintain($owner, $organization, $conversation, $session, (string) Str::uuid());
        $web = $this->actingAs($owner)->withSession([
            'access_mode' => 'workspace', 'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1, 'credential_generation' => 1,
        ]);
        $web->get(route('ai-common.shared.show', $conversation))->assertOk()
            ->assertSee('One Shared CO / Conversation Atmosphere')->assertSee('Context watermark')
            ->assertSee('Rolling Context checkpoint')->assertSee('Bounded historical retrieval')
            ->assertDontSee('thinking', false);
        $response = $web->getJson(route('ai-common.shared.sessions.snapshot', [$conversation, $session]).'?client_instance_id='.Str::uuid().'&cursor=0');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('context', 'current');
    }

    private function activeFixture(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'B P4 Synthetic', 'slug' => 'b-p4-'.Str::lower((string) Str::ulid())]);
        $this->attach($owner, $organization, 'owner');
        $this->attach($member, $organization, 'member');
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id, 'is_enabled' => true, 'allows_transcription' => true,
            'allowed_categories' => ['common_entry'], 'version' => 1,
            'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        $conversations = app(AiCommonSharedConversationWriter::class);
        $conversation = $conversations->create($owner, $organization, [
            'operation_id' => (string) Str::uuid(), 'name' => 'P4 Shared', 'purpose' => 'Bounded long conversation context',
        ]);
        $invitation = $conversations->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
        $conversations->accept($member, $organization, $invitation);
        $writer = app(AiCommonSharedSessionWriter::class);
        $session = $writer->prepare($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'mode' => 'shared_room']);
        foreach ([$owner, $member] as $user) {
            $writer->decideConsent($user, $organization, $conversation, $session, [
                'operation_id' => (string) Str::uuid(),
                'consents' => array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, 'granted'),
            ]);
        }
        $session = $writer->activate($owner, $organization, $conversation, $session);
        $stream = $writer->startStream($owner, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(), 'client_instance_id' => (string) Str::uuid(), 'mode' => 'shared_room',
        ]);

        return [$owner, $member, $organization, $conversation->fresh(), $session, $stream];
    }

    private function transcribe(User $owner, Organization $organization, $conversation, $session, $stream, int $sequence, string $text)
    {
        $this->transcription->text = $text;
        $audio = app(AiCommonSharedSessionAudioWriter::class);
        $window = $audio->recordWindow(
            $owner, $organization, $conversation, $session, $stream,
            UploadedFile::fake()->createWithContent('window-'.$sequence.'.webm', 'audio-'.$sequence),
            $stream->generation, $sequence, (string) Str::uuid(),
        );

        return $audio->transcribe($owner, $organization, $conversation, $session, $window, (string) Str::uuid());
    }

    private function attach(User $user, Organization $organization, string $role): void
    {
        $organization->users()->attach($user->id, [
            'role' => $role, 'organization_role' => $role, 'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now(),
        ]);
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id, 'classification_version' => 'b-p4-test',
            'classified_at' => now(), 'evidence_ref' => 'b-p4-test',
        ]);
    }
}

class Bp4AudioInspector implements AiCommonAudioInspector
{
    public function inspect(string $binary, string $extension, string $mimeType): array
    {
        return ['mime_type' => 'audio/webm', 'extension' => 'webm', 'codec' => 'opus', 'duration_ms' => 20_000];
    }
}

class Bp4TranscriptionProvider implements AiCommonTranscriptionProvider
{
    public string $text = 'Synthetic transcript';

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        return [
            'text' => $this->text,
            'segments' => [['speaker' => 'Speaker A', 'text' => $this->text, 'start_ms' => 0, 'end_ms' => 20_000, 'confidence' => 0.9]],
            'provider' => 'synthetic', 'model' => 'bounded-diarization-v1',
            'usage' => ['unit' => 'duration_seconds', 'quantity' => '20', 'estimated_cost_microunits' => null],
        ];
    }
}

class Bp4ChatProvider implements AiCommonProvider
{
    public array $calls = [];

    public function respond(array $messages, array $sources): array
    {
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode(' ');
        $type = str_contains($system, 'Maintain bounded rolling context') ? 'maintenance'
            : (str_contains($system, 'bounded candidate lines') ? 'organize' : 'co');
        $this->calls[] = ['type' => $type, 'messages' => $messages, 'sources' => $sources];
        $answer = match ($type) {
            'maintenance' => json_encode([
                'current_topic' => ['bounded release'],
                'main_views' => ['Speaker A supports 98765', 'Speaker B opposes 98765'],
                'agreement_candidates' => [], 'open_questions' => ['verify cost'],
                'to_confirm' => [], 'source_refs' => [],
            ], JSON_THROW_ON_ERROR),
            'organize' => "decision: Verify the bounded release\nunresolved: Confirm provider cost\naction_candidate: Request human approval",
            default => 'Shared CO bounded answer',
        };

        return ['answer' => $answer, 'citations' => [], 'provider' => 'synthetic', 'model' => 'p4-context-v1', 'input_tokens' => 120, 'output_tokens' => 30];
    }

    public function count(string $type): int
    {
        return collect($this->calls)->where('type', $type)->count();
    }
}
