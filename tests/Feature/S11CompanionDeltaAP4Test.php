<?php

namespace Tests\Feature;

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonAttachmentDerivative;
use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCommon\AiCommonAttachmentTranscriptWriter;
use App\Services\AiCommon\AiCommonAttachmentWriter;
use App\Services\AiCommon\AiCommonProposalContract;
use App\Services\AiCommon\AiCommonProposalFactory;
use App\Services\AiCommon\AiCommonSourceManifest;
use App\Services\AiCommon\AiCommonTemporaryAudioWriter;
use App\Services\AiCommon\AiCommonTranscriptionException;
use App\Services\AiProposalApplier;
use App\Services\AiProposalApprover;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class S11CompanionDeltaAP4Test extends TestCase
{
    use RefreshDatabase;

    private P4TranscriptionProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ai_common_attachments');
        Storage::fake('ai_common_temporary_audio');
        $this->provider = new P4TranscriptionProvider;
        $this->app->instance(AiCommonAudioInspector::class, new P4AudioInspector);
        $this->app->instance(AiCommonTranscriptionProvider::class, $this->provider);
    }

    public function test_transcription_usage_and_audit_are_idempotent_sanitized_and_keep_unknown_cost_null(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'private audio bytes');
        $operationId = (string) Str::uuid();
        $writer = app(AiCommonAttachmentTranscriptWriter::class);

        $revision = $writer->transcribe($owner, $organization, $conversation, $attachment, $operationId, true);
        $replay = $writer->transcribe($owner, $organization, $conversation, $attachment, $operationId, true);

        $this->assertSame($revision->id, $replay->id);
        $this->assertSame(1, $this->provider->calls);
        $this->assertDatabaseCount('ai_common_attachment_transcription_operations', 1);
        $this->assertDatabaseCount('ai_usage_ledgers', 1);
        $this->assertDatabaseCount('ai_common_audit_events', 1);
        $this->assertDatabaseHas('ai_usage_ledgers', [
            'application_operation_id' => $operationId,
            'purpose' => 'transcription',
            'attempt' => 1,
            'result' => 'success',
            'usage_unit' => 'audio_tokens',
            'usage_quantity' => '42.000000',
            'media_duration_ms' => 60_000,
            'estimated_cost_microunits' => null,
        ]);
        $serialized = json_encode([
            'audit' => \DB::table('ai_common_audit_events')->get(),
            'usage' => \DB::table('ai_usage_ledgers')->get(),
        ]);
        $this->assertStringNotContainsString('provider private transcript', $serialized);
        $this->assertStringNotContainsString('private audio bytes', $serialized);
        $this->assertStringNotContainsString($attachment->display_name, $serialized);
        $this->assertStringNotContainsString($attachment->storage_key, $serialized);
    }

    public function test_known_provider_cost_requires_explicit_price_and_currency_evidence(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'known cost audio');
        $operationId = (string) Str::uuid();
        $this->provider->usage = [
            'unit' => 'duration_seconds',
            'quantity' => 60,
            'estimated_cost_microunits' => 1250,
            'price_version' => 'p4-synthetic-v1',
            'currency' => 'JPY',
        ];

        app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
            $owner, $organization, $conversation, $attachment, $operationId, true,
        );

        $this->assertDatabaseHas('ai_usage_ledgers', [
            'application_operation_id' => $operationId,
            'usage_unit' => 'duration_seconds',
            'usage_quantity' => '60.000000',
            'media_duration_ms' => 60_000,
            'estimated_cost_microunits' => 1250,
            'price_version' => 'p4-synthetic-v1',
            'currency' => 'JPY',
            'result' => 'success',
        ]);
        $this->assertDatabaseCount('ai_usage_ledgers', 1);
        $this->assertDatabaseCount('ai_common_audit_events', 1);
    }

    public function test_unknown_provider_result_is_recorded_once_without_blind_retry_or_zero_cost(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'response loss audio');
        $operationId = (string) Str::uuid();
        $this->provider->error = new AiCommonTranscriptionException('transcription_result_unknown', true);
        $writer = app(AiCommonAttachmentTranscriptWriter::class);

        foreach ([1, 2] as $attempt) {
            try {
                $writer->transcribe($owner, $organization, $conversation, $attachment, $operationId, true);
                $this->fail('Unknown provider result was accepted on attempt '.$attempt.'.');
            } catch (ValidationException) {
                $this->assertSame(1, $this->provider->calls);
            }
        }

        $this->assertDatabaseCount('ai_usage_ledgers', 1);
        $this->assertDatabaseCount('ai_common_audit_events', 1);
        $this->assertDatabaseHas('ai_usage_ledgers', [
            'application_operation_id' => $operationId,
            'result' => 'unknown',
            'estimated_cost_microunits' => null,
        ]);
        $this->assertDatabaseCount('ai_common_transcript_revisions', 0);
    }

    public function test_provider_result_is_discarded_after_revoke_and_never_becomes_transcript(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'late private audio');
        $operationId = (string) Str::uuid();
        $this->provider->duringCall = function () use ($owner, $organization, $conversation, $attachment): void {
            app(AiCommonAttachmentWriter::class)->revoke(
                $owner,
                $organization,
                $conversation,
                $attachment->fresh(),
                ['operation_id' => (string) Str::uuid(), 'reason' => 'P4 current authorization evidence'],
            );
        };

        try {
            app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
                $owner, $organization, $conversation, $attachment, $operationId, true,
            );
            $this->fail('A provider result survived source revoke.');
        } catch (AuthorizationException|ValidationException) {
            $this->assertDatabaseHas('ai_common_attachment_transcription_operations', [
                'operation_id' => $operationId,
                'result_status' => 'discarded',
            ]);
            $this->assertDatabaseHas('ai_usage_ledgers', [
                'application_operation_id' => $operationId,
                'result' => 'discarded',
            ]);
            $this->assertDatabaseHas('ai_common_audit_events', [
                'operation_id' => $operationId,
                'result' => 'discarded',
            ]);
            $this->assertDatabaseCount('ai_common_transcript_revisions', 0);
        }
    }

    public function test_storage_failure_is_audited_without_inventing_a_provider_attempt(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $audio = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('missing.webm', 'missing before provider'),
            (string) Str::uuid(),
        );
        Storage::disk('ai_common_temporary_audio')->delete($audio->storage_key);
        $operationId = (string) Str::uuid();

        try {
            $writer->transcribe($owner, $organization, $conversation, $audio, $operationId, true);
            $this->fail('Missing temporary audio was sent to the provider.');
        } catch (ValidationException) {
            $this->assertSame(0, $this->provider->calls);
            $this->assertDatabaseCount('ai_usage_ledgers', 0);
            $this->assertDatabaseHas('ai_common_audit_events', [
                'operation_id' => $operationId,
                'result' => 'failed',
                'safe_error_code' => 'temporary_audio_integrity_failed',
            ]);
            $metadata = json_decode((string) \DB::table('ai_common_audit_events')
                ->where('operation_id', $operationId)->value('metadata'), true);
            $this->assertSame(0, $metadata['attempt']);
            $this->assertFalse($metadata['usage_known']);
        }
    }

    public function test_temporary_voice_records_separate_application_and_provider_evidence(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonTemporaryAudioWriter::class);
        $audio = $writer->record(
            $owner,
            $organization,
            $conversation,
            UploadedFile::fake()->createWithContent('private.webm', 'temporary private audio'),
            (string) Str::uuid(),
        );
        $operationId = (string) Str::uuid();

        $draft = $writer->transcribe($owner, $organization, $conversation, $audio, $operationId, true);

        $this->assertSame('draft', $draft->state);
        $this->assertSame('provider private transcript', $draft->draft_text);
        $this->assertDatabaseHas('ai_usage_ledgers', [
            'application_operation_id' => $operationId,
            'purpose' => 'transcription',
            'attempt' => 1,
            'result' => 'success',
            'usage_unit' => 'audio_tokens',
            'media_duration_ms' => 45_000,
        ]);
        $this->assertDatabaseHas('ai_common_audit_events', [
            'operation_id' => $operationId,
            'subject_type' => 'temporary_audio',
            'result' => 'success',
        ]);
        $audit = json_encode(\DB::table('ai_common_audit_events')->where('operation_id', $operationId)->first());
        $this->assertStringNotContainsString('provider private transcript', $audit);
        $this->assertStringNotContainsString('temporary private audio', $audit);
    }

    public function test_bounded_attachment_extract_source_keeps_lineage_when_proposal_is_created(): void
    {
        [$owner, $member, $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'bounded attachment bytes');
        $attachment->update([
            'display_name' => 'private-source.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'extension' => 'docx',
            'duration_ms' => null,
            'media_codec' => null,
        ]);
        $content = 'bounded extracted content';
        $selector = ['paragraph_from' => 1, 'paragraph_to' => 1];
        $derivative = AiCommonAttachmentDerivative::query()->create([
            'ai_common_attachment_id' => $attachment->id,
            'created_by_user_id' => $owner->id,
            'operation_id' => (string) Str::uuid(),
            'payload_fingerprint' => hash('sha256', 'p4-extract-operation'),
            'kind' => AiCommonAttachmentDerivative::KIND_TEXT_EXTRACT,
            'state' => AiCommonAttachmentDerivative::STATE_READY,
            'attachment_version' => $attachment->version,
            'source_sha256' => $attachment->sha256,
            'selector' => $selector,
            'selector_fingerprint' => hash('sha256', json_encode($selector)),
            'extractor_driver' => 'p4-synthetic',
            'extractor_version' => '1',
            'content' => $content,
            'content_sha256' => hash('sha256', $content),
            'character_count' => mb_strlen($content),
        ]);
        app(AiCommonAttachmentWriter::class)->setAiReference(
            $owner, $organization, $conversation, $attachment,
            ['operation_id' => (string) Str::uuid(), 'enabled' => true],
        );
        $source = app(AiCommonSourceManifest::class)->select(
            $owner, $organization, $conversation, 'attachment_extract', $derivative->public_id, 'P4 bounded extract',
        );
        $message = $conversation->messages()->create([
            'role' => AiCommonMessage::ROLE_ASSISTANT,
            'content' => 'Extract-derived private response',
            'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
            'source_lineage_version' => AiCommonMessage::SOURCE_LINEAGE_V1,
        ]);
        $message->sourceRevisions()->sync([$source->current_revision_id]);

        $proposal = app(AiCommonProposalFactory::class)->create($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::CAPTURE_CREATE,
            'title' => 'P4 extract source proposal',
            'idempotency_key' => (string) Str::uuid(),
            'source_message_public_id' => $message->public_id,
            'attributes' => [
                'type' => 'request',
                'body' => 'Bounded extract handoff',
                'recipient_user_id' => $member->id,
                'notification_timing' => 'now',
                'recipient_confirmed' => true,
            ],
        ]);

        $this->assertSame([$source->current_revision_id], $proposal->sourceRevisions->pluck('id')->all());
        $this->assertDatabaseHas('ai_common_audit_events', [
            'event' => 'proposal.source_created',
            'subject_public_id' => $proposal->public_id,
        ]);
        $audit = json_encode(\DB::table('ai_common_audit_events')->where('subject_public_id', $proposal->public_id)->first());
        $this->assertStringNotContainsString($content, $audit);
        $this->assertStringNotContainsString('Extract-derived private response', $audit);
        $this->assertStringNotContainsString('Bounded extract handoff', $audit);
    }

    public function test_transcript_source_proposal_reuses_existing_engine_writer_and_private_lineage(): void
    {
        [$owner, $member, $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'proposal audio');
        $revision = app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
            $owner, $organization, $conversation, $attachment, (string) Str::uuid(), true,
        );
        app(AiCommonAttachmentWriter::class)->setAiReference(
            $owner,
            $organization,
            $conversation,
            $attachment,
            ['operation_id' => (string) Str::uuid(), 'enabled' => true],
        );
        $source = app(AiCommonSourceManifest::class)->select(
            $owner,
            $organization,
            $conversation,
            'attachment_transcript',
            $revision->public_id,
            'P4 transcript source',
        );
        $message = $conversation->messages()->create([
            'role' => AiCommonMessage::ROLE_ASSISTANT,
            'content' => 'Private source-derived response',
            'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
            'source_lineage_version' => AiCommonMessage::SOURCE_LINEAGE_V1,
        ]);
        $message->sourceRevisions()->sync([$source->current_revision_id]);
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'operation' => AiCommonProposalContract::CAPTURE_CREATE,
            'title' => 'P4 source proposal',
            'idempotency_key' => $idempotencyKey,
            'source_message_public_id' => $message->public_id,
            'attributes' => [
                'type' => 'request',
                'body' => 'Approved bounded handoff',
                'recipient_user_id' => $member->id,
                'notification_timing' => 'now',
                'recipient_confirmed' => true,
            ],
        ];
        $factory = app(AiCommonProposalFactory::class);
        $proposal = $factory->create($owner, $organization, $conversation, $payload);
        $replay = $factory->create($owner, $organization, $conversation, $payload);

        $this->assertSame($proposal->id, $replay->id);
        $this->assertSame([$source->current_revision_id], $proposal->sourceRevisions->pluck('id')->all());
        $this->assertDatabaseCount('ai_proposals', 1);
        $this->assertDatabaseHas('ai_common_audit_events', [
            'event' => 'proposal.source_created',
            'subject_public_id' => $proposal->public_id,
            'result' => 'success',
        ]);

        app(AiProposalApprover::class)->approve($proposal, $owner);
        app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
        app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
        $this->assertDatabaseCount('captures', 1);
        $this->assertDatabaseCount('company_notifications', 1);
        $this->assertDatabaseCount('ai_proposal_apply_attempts', 1);
        $audit = json_encode(\DB::table('ai_common_audit_events')->where('event', 'proposal.source_created')->first());
        $this->assertStringNotContainsString('Private source-derived response', $audit);
        $this->assertStringNotContainsString('Approved bounded handoff', $audit);
        $this->assertStringNotContainsString('provider private transcript', $audit);
    }

    public function test_source_revoke_blocks_proposal_approval_and_unit_write(): void
    {
        [$owner, $member, $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'revoked proposal audio');
        $revision = app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
            $owner, $organization, $conversation, $attachment, (string) Str::uuid(), true,
        );
        app(AiCommonAttachmentWriter::class)->setAiReference(
            $owner, $organization, $conversation, $attachment,
            ['operation_id' => (string) Str::uuid(), 'enabled' => true],
        );
        $source = app(AiCommonSourceManifest::class)->select(
            $owner, $organization, $conversation, 'attachment_transcript', $revision->public_id, 'P4 revoke source',
        );
        $message = $conversation->messages()->create([
            'role' => AiCommonMessage::ROLE_ASSISTANT,
            'content' => 'Must become unusable',
            'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
            'source_lineage_version' => AiCommonMessage::SOURCE_LINEAGE_V1,
        ]);
        $message->sourceRevisions()->sync([$source->current_revision_id]);
        $proposal = app(AiCommonProposalFactory::class)->create($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::CAPTURE_CREATE,
            'title' => 'P4 blocked proposal',
            'idempotency_key' => (string) Str::uuid(),
            'source_message_public_id' => $message->public_id,
            'attributes' => [
                'type' => 'request', 'body' => 'Must not apply', 'recipient_user_id' => $member->id,
                'notification_timing' => 'now', 'recipient_confirmed' => true,
            ],
        ]);
        app(AiCommonAttachmentWriter::class)->revoke(
            $owner, $organization, $conversation, $attachment->fresh(),
            ['operation_id' => (string) Str::uuid(), 'reason' => 'P4 revoke before approval'],
        );

        try {
            app(AiProposalApprover::class)->approve($proposal->fresh(), $owner);
            $this->fail('A source-derived proposal remained approvable after revoke.');
        } catch (AuthorizationException|ValidationException) {
            $this->assertDatabaseHas('ai_proposals', ['id' => $proposal->id, 'status' => 'pending']);
            $this->assertDatabaseCount('captures', 0);
            $this->assertDatabaseCount('company_notifications', 0);
        }
    }

    private function attachment(User $owner, Organization $organization, AiCommonConversation $conversation, string $binary): AiCommonAttachment
    {
        $publicId = (string) Str::ulid();
        $key = $organization->public_id.'/'.$conversation->public_id.'/'.$publicId;
        Storage::disk('ai_common_attachments')->put($key, $binary);

        return AiCommonAttachment::query()->create([
            'public_id' => $publicId,
            'organization_id' => $organization->id,
            'ai_common_conversation_id' => $conversation->id,
            'uploaded_by_user_id' => $owner->id,
            'variant' => AiCommonAttachment::VARIANT_UPLOAD,
            'state' => AiCommonAttachment::STATE_READY,
            'version' => 2,
            'display_name' => 'private-p4-source.webm',
            'mime_type' => 'audio/webm',
            'extension' => 'webm',
            'size_bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
            'storage_key' => $key,
            'inspection_status' => 'passed',
            'inspection_driver' => 'p4-test',
            'inspection_version' => '1',
            'duration_ms' => 60_000,
            'media_codec' => 'opus',
            'allows_ai_reference' => false,
            'ai_reference_version' => 1,
            'uploader_access_epoch' => 1,
            'uploader_credential_generation' => 1,
            'ready_at_utc' => now('UTC'),
            'inspected_at_utc' => now('UTC'),
        ]);
    }

    private function conversation(User $owner, Organization $organization): AiCommonConversation
    {
        return AiCommonConversation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Delta A P4 private conversation',
            'status' => AiCommonConversation::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }

    private function tenant(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Delta A P4 Synthetic',
            'slug' => 'delta-a-p4-'.Str::lower((string) Str::ulid()),
        ]);
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Delta A P4',
            'slug' => 'delta-a-p4-'.Str::lower((string) Str::ulid()),
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
                'classification_version' => 'p4-test',
                'classified_at' => now(),
                'evidence_ref' => 'p4-test',
            ]);
        }
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id,
            'is_enabled' => true,
            'allows_transcription' => true,
            'allowed_categories' => ['common_entry', 'attachment', 'capture'],
            'version' => 1,
            'managed_by_user_id' => $owner->id,
            'confirmed_at' => now(),
        ]);

        return [$owner, $member, $organization, $workspace];
    }
}

class P4AudioInspector implements AiCommonAudioInspector
{
    public function inspect(string $binary, string $extension, string $mimeType): array
    {
        return [
            'mime_type' => 'audio/webm',
            'extension' => 'webm',
            'codec' => 'opus',
            'duration_ms' => 45_000,
        ];
    }
}

class P4TranscriptionProvider implements AiCommonTranscriptionProvider
{
    public int $calls = 0;

    public ?\Closure $duringCall = null;

    public ?AiCommonTranscriptionException $error = null;

    public array $usage = [
        'unit' => 'audio_tokens',
        'quantity' => 42,
    ];

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        $this->calls++;
        ($this->duringCall) && ($this->duringCall)();
        if ($this->error) {
            throw $this->error;
        }

        return [
            'text' => 'provider private transcript',
            'provider' => 'p4-synthetic',
            'model' => 'p4-transcription-v1',
            'usage' => $this->usage,
        ];
    }
}
