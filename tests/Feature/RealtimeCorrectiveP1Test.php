<?php

namespace Tests\Feature;

use App\Models\AiCommonSharedProviderSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedSessionWriter;
use App\Services\AiCommon\Realtime\CanonicalAudioFrame;
use App\Services\AiCommon\Realtime\DeepgramStreamingAdapter;
use App\Services\AiCommon\Realtime\RealtimeLeaseManager;
use App\Services\AiCommon\Realtime\RealtimeProviderEventStore;
use App\Services\AiCommon\Realtime\RealtimeSourceLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RealtimeCorrectiveP1Test extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_contains_exact_eight_realtime_tables(): void
    {
        $tables = [
            'ai_common_shared_relay_leases', 'ai_common_shared_relay_control_events',
            'ai_common_shared_source_ranges', 'ai_common_shared_provider_sessions',
            'ai_common_shared_provider_send_ranges', 'ai_common_shared_provider_event_receipts',
            'ai_common_shared_durable_final_commits', 'ai_common_shared_durable_final_commit_items',
        ];
        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasTable('ai_common_shared_audio_windows'));
        $this->assertTrue(Schema::hasTable('ai_common_shared_transcript_revisions'));
    }

    public function test_realtime_schema_has_required_unique_indexes_and_foreign_keys(): void
    {
        $indexes = collect(Schema::getIndexes('ai_common_shared_source_ranges'))->pluck('name');
        $this->assertContains('acrrange_stream_generation_sequence_uq', $indexes);
        $this->assertContains('acrrange_stream_generation_event_uq', $indexes);
        $this->assertContains('acrrange_stream_range_idx', $indexes);

        $sendIndexes = collect(Schema::getIndexes('ai_common_shared_provider_send_ranges'))->pluck('name');
        $this->assertContains('acrsend_provider_ordinal_uq', $sendIndexes);
        $this->assertContains('acrsend_provider_range_uq', $sendIndexes);

        $foreignTables = collect(DB::select("PRAGMA foreign_key_list('ai_common_shared_durable_final_commit_items')"))->pluck('table');
        $this->assertContains('ai_common_shared_durable_final_commits', $foreignTables);
        $this->assertContains('ai_common_shared_transcript_segments', $foreignTables);
        $this->assertContains('ai_common_shared_transcript_revisions', $foreignTables);
    }

    public function test_lease_source_cursor_send_ledger_and_synthetic_receipt_are_fail_closed(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $lease = app(RealtimeLeaseManager::class)->issue($owner, $organization, $conversation, $session, $stream);
        $this->assertSame('active', $lease->state);
        $this->assertSame($lease->id, app(RealtimeLeaseManager::class)->issue($owner, $organization, $conversation, $session, $stream)->id);

        $ledger = app(RealtimeSourceLedger::class);
        $firstEventId = (string) Str::uuid();
        $first = $ledger->accept($this->frame($lease->public_id, $stream->public_id, 1, 1, 0, 1600, 'a', $firstEventId), $lease);
        $this->assertSame($first->id, $ledger->accept($this->frame($lease->public_id, $stream->public_id, 1, 1, 0, 1600, 'a', $firstEventId), $lease)->id);
        try {
            $ledger->accept($this->frame($lease->public_id, $stream->public_id, 1, 1, 0, 1600, 'a'), $lease);
            $this->fail('A reused sequence with a different event identity must fail closed.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('integrity_conflict', json_encode($error->errors()));
        }
        try {
            $ledger->accept($this->frame($lease->public_id, $stream->public_id, 1, 2, 3200, 4800, 'b'), $lease);
            $this->fail('A source gap must fail closed.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('source_gap', json_encode($error->errors()));
        }
        $second = $ledger->accept($this->frame($lease->public_id, $stream->public_id, 1, 2, 1600, 3200, 'b'), $lease);

        $provider = AiCommonSharedProviderSession::query()->create([
            'relay_lease_id' => $lease->id, 'ai_common_shared_capture_stream_id' => $stream->id,
            'generation' => 1, 'adapter' => 'deepgram', 'adapter_version' => 'synthetic-v1',
            'capability_profile_version' => 'nova3-ja-v1', 'state' => 'synthetic',
        ]);
        $ledger->sent($provider, $first);
        $ledger->sent($provider, $second);
        $mapping = $ledger->sourceMapping($provider, 0, 3200);
        $this->assertSame(['verification_state' => 'verified', 'start_sample' => 0, 'end_sample' => 3200], $mapping);

        $adapter = app(DeepgramStreamingAdapter::class);
        $partial = $adapter->normalize($this->providerEvent(false, '途中'), $provider->public_id, 1, $mapping);
        $this->assertNull(app(RealtimeProviderEventStore::class)->persist($partial, $provider));
        $this->assertDatabaseCount('ai_common_shared_provider_event_receipts', 0);

        $final = $adapter->normalize($this->providerEvent(true, '確定しました'), $provider->public_id, 2, $mapping);
        $receipt = app(RealtimeProviderEventStore::class)->persist($final, $provider);
        $this->assertSame('accepted', $receipt->status);
        $this->assertSame('確定しました', $receipt->normalized_final_metadata['content']);
        $this->assertSame(0, $receipt->provider_start_sample);
        $this->assertSame(3200, $receipt->provider_duration_samples);
        $this->assertSame($receipt->id, app(RealtimeProviderEventStore::class)->persist($final, $provider)->id);
        $this->assertStringNotContainsString('確定しました', (string) DB::table('ai_common_shared_provider_event_receipts')->where('id', $receipt->id)->value('normalized_final_metadata'));

        try {
            app(RealtimeProviderEventStore::class)->persist($adapter->normalize(['type' => 'Metadata', 'request_id' => (string) Str::uuid()], $provider->public_id, 1, $mapping), $provider);
            $this->fail('An out-of-order provider event must fail closed.');
        } catch (ValidationException $error) {
            $this->assertStringContainsString('provider_event_late', json_encode($error->errors()));
        }

        foreach ([3 => 'Metadata', 4 => 'Error', 5 => 'Close'] as $order => $type) {
            $stored = app(RealtimeProviderEventStore::class)->persist($adapter->normalize([
                'type' => $type, 'request_id' => (string) Str::uuid(), 'authorization' => 'must-not-persist',
            ], $provider->public_id, $order, $mapping), $provider);
            $this->assertSame(strtolower($type), $stored->normalized_event_type);
            $this->assertNull($stored->normalized_final_metadata);
        }

        $lease->update(['state' => 'revoking', 'safe_reason_code' => 'normal_stop', 'revoked_at_utc' => now()]);
        $late = app(RealtimeProviderEventStore::class)->persist(
            $adapter->normalize($this->providerEvent(true, '遅い確定'), $provider->public_id, 6, $mapping),
            $provider,
        );
        $this->assertSame('rejected', $late->status);
        $this->assertSame('provider_event_late', $late->safe_reason_code);
        $this->assertDatabaseCount('ai_common_shared_provider_event_receipts', 5);
    }

    public function test_pause_closes_relay_and_resume_requires_a_new_generation(): void
    {
        [$owner, , $organization, $conversation, $session, $stream] = $this->activeFixture();
        $lease = app(RealtimeLeaseManager::class)->issue($owner, $organization, $conversation, $session, $stream);
        $sessions = app(AiCommonSharedSessionWriter::class);

        $paused = $sessions->pause($owner, $organization, $conversation, $session);
        $this->assertSame('paused', $paused->state);
        $this->assertDatabaseHas('ai_common_shared_relay_leases', ['id' => $lease->id, 'state' => 'revoking', 'safe_reason_code' => 'normal_stop']);
        $this->assertDatabaseHas('ai_common_shared_relay_control_events', ['relay_lease_id' => $lease->id, 'event_type' => 'lifecycle_close', 'target_generation' => 1]);

        $resumed = $sessions->resume($owner, $organization, $conversation, $paused);
        $this->assertSame('active', $resumed->state);
        $this->assertSame('stopped', $stream->fresh()->state);
        $replacement = $sessions->startStream($owner, $organization, $conversation, $resumed, [
            'operation_id' => (string) Str::uuid(), 'client_instance_id' => (string) Str::uuid(), 'mode' => 'shared_room',
        ]);
        $this->assertSame(2, $replacement->generation);
        $this->assertNotSame($lease->id, app(RealtimeLeaseManager::class)->issue($owner, $organization, $conversation, $resumed, $replacement)->id);
    }

    private function activeFixture(): array
    {
        config()->set('ai-common-realtime.enabled', true);
        config()->set('ai-common-realtime.audio_send_enabled', false);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Realtime Synthetic', 'slug' => 'rt-'.Str::lower((string) Str::ulid())]);
        $this->attach($owner, $organization, 'owner');
        $this->attach($member, $organization, 'member');
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id, 'is_enabled' => true, 'allows_transcription' => true,
            'allowed_categories' => ['common_entry'], 'version' => 1,
            'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        $conversations = app(AiCommonSharedConversationWriter::class);
        $conversation = $conversations->create($owner, $organization, ['operation_id' => (string) Str::uuid(), 'name' => 'Realtime', 'purpose' => 'Realtime contract']);
        $invitation = $conversations->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
        $conversations->accept($member, $organization, $invitation);
        $sessions = app(AiCommonSharedSessionWriter::class);
        $session = $sessions->prepare($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'mode' => 'shared_room']);
        foreach ([$owner, $member] as $user) {
            $sessions->decideConsent($user, $organization, $conversation, $session, [
                'operation_id' => (string) Str::uuid(),
                'consents' => array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, 'granted'),
            ]);
        }
        $session = $sessions->activate($owner, $organization, $conversation, $session);
        $stream = $sessions->startStream($owner, $organization, $conversation, $session, [
            'operation_id' => (string) Str::uuid(), 'client_instance_id' => (string) Str::uuid(), 'mode' => 'shared_room',
        ]);

        return [$owner, $member, $organization, $conversation->fresh(), $session, $stream];
    }

    private function attach(User $user, Organization $organization, string $role): void
    {
        $organization->users()->attach($user->id, [
            'role' => $role, 'organization_role' => $role, 'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now(),
        ]);
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id, 'classification_version' => 'ce-p1-test',
            'classified_at' => now(), 'evidence_ref' => 'ce-p1-test',
        ]);
    }

    private function frame(string $lease, string $stream, int $generation, int $sequence, int $start, int $end, string $hashSeed, ?string $clientEventId = null): CanonicalAudioFrame
    {
        return CanonicalAudioFrame::fromArray([
            'lease_id' => $lease, 'stream_id' => $stream, 'generation' => $generation,
            'sequence' => $sequence, 'client_event_id' => $clientEventId ?? (string) Str::uuid(),
            'start_sample' => $start, 'end_sample' => $end, 'sample_count' => $end - $start,
            'sample_rate' => 16000, 'bit_depth' => 16, 'channels' => 1, 'format' => 'pcm_s16le',
            'content_sha256' => hash('sha256', $hashSeed),
        ]);
    }

    private function providerEvent(bool $final, string $text): array
    {
        return ['type' => 'Results', 'request_id' => (string) Str::uuid(), 'is_final' => $final, 'start' => 0, 'duration' => .2,
            'channel' => ['alternatives' => [['transcript' => $text, 'words' => [['start' => 0, 'end' => .2, 'speaker' => 0, 'confidence' => .99]]]]]];
    }
}
