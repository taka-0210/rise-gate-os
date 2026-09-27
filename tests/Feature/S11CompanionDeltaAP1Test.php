<?php

namespace Tests\Feature;

use App\Contracts\AiCommonProvider;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\ProductAccountEligibility;
use App\Models\ProjectInternalNoteAttachment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiCommon\AiCommonAttachmentAccess;
use App\Services\AiCommon\AiCommonAttachmentWriter;
use App\Services\AiCommon\AiCommonHumanMessageWriter;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class S11CompanionDeltaAP1Test extends TestCase
{
    use RefreshDatabase;

    private S11DeltaAP1Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new S11DeltaAP1Provider;
        $this->app->instance(AiCommonProvider::class, $this->provider);
    }

    public function test_upload_reservation_is_private_idempotent_and_ai_reference_off(): void
    {
        [$owner, $member, $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $operationId = (string) Str::uuid();
        $input = [
            'operation_id' => $operationId,
            'display_name' => 'quarterly-plan.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 4096,
        ];
        $writer = app(AiCommonAttachmentWriter::class);
        $attachment = $writer->reserveUpload($owner, $organization, $conversation, $input);
        $replayed = $writer->reserveUpload($owner, $organization, $conversation, $input);

        $this->assertSame($attachment->id, $replayed->id);
        $this->assertSame(AiCommonAttachment::STATE_RECEIVING, $attachment->state);
        $this->assertFalse($attachment->allows_ai_reference);
        $this->assertNull($attachment->sha256);
        $this->assertStringNotContainsString('quarterly-plan.pdf', $attachment->storage_key);
        $this->assertDatabaseCount('ai_common_attachments', 1);
        $this->assertDatabaseCount('ai_common_attachment_operations', 1);

        try {
            $writer->reserveUpload($owner, $organization, $conversation, array_merge($input, ['display_name' => 'changed.pdf']));
            $this->fail('An operation ID was reused with a different payload.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_common_attachments', 1);
        }

        $this->expectException(AuthorizationException::class);
        $writer->reserveUpload($member, $organization, $conversation, array_merge($input, ['operation_id' => (string) Str::uuid()]));
    }

    public function test_upload_reservation_rejects_unsupported_or_oversized_metadata(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonAttachmentWriter::class);
        foreach ([
            ['display_name' => 'payload.exe', 'mime_type' => 'application/octet-stream', 'size_bytes' => 20],
            ['display_name' => 'photo.png', 'mime_type' => 'image/jpeg', 'size_bytes' => 20],
            ['display_name' => 'large.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10 * 1024 * 1024 + 1],
        ] as $invalid) {
            try {
                $writer->reserveUpload($owner, $organization, $conversation, array_merge($invalid, ['operation_id' => (string) Str::uuid()]));
                $this->fail('Unsafe upload metadata was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('ai_common_attachments', 0);
            }
        }
    }

    public function test_existing_resource_is_a_relation_and_fails_closed_when_origin_disappears(): void
    {
        Storage::fake('local');
        Storage::fake('ai_common_attachments');
        [$owner, , $organization, $workspace] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, [
            'name' => 'Attachment origin', 'purpose' => 'Synthetic', 'expected_outcome' => 'Evidence',
        ]);
        $note = $project->internalNotes()->create(['user_id' => $owner->id, 'body' => 'Synthetic note']);
        Storage::disk('local')->put('project-internal-notes/synthetic/source.pdf', 'synthetic-pdf');
        $origin = ProjectInternalNoteAttachment::query()->create([
            'project_internal_note_id' => $note->id,
            'project_id' => $project->id,
            'uploaded_by' => $owner->id,
            'original_name' => 'source.pdf',
            'stored_path' => 'project-internal-notes/synthetic/source.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen('synthetic-pdf'),
            'sha256' => hash('sha256', 'synthetic-pdf'),
        ]);

        $attachment = app(AiCommonAttachmentWriter::class)->referenceExisting(
            $owner,
            $organization,
            $conversation,
            $origin,
            ['operation_id' => (string) Str::uuid()],
        );

        $this->assertSame(AiCommonAttachment::VARIANT_EXISTING, $attachment->variant);
        $this->assertSame(AiCommonAttachment::STATE_QUARANTINE, $attachment->state);
        $this->assertNull($attachment->storage_key);
        $this->assertSame($origin->public_id, $attachment->origin_public_id);
        $this->assertSame([], Storage::disk('ai_common_attachments')->allFiles());

        Storage::disk('local')->delete($origin->stored_path);
        $this->expectException(ValidationException::class);
        app(AiCommonAttachmentAccess::class)->authorizeAttachment(
            $owner,
            $organization,
            $conversation,
            $attachment->fresh(),
        );
    }

    public function test_human_message_is_idempotent_and_never_calls_provider_even_when_ai_is_off(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        OrganizationAiPolicy::query()->where('organization_id', $organization->id)
            ->update(['is_enabled' => false, 'version' => 2]);
        $operationId = (string) Str::uuid();
        $writer = app(AiCommonHumanMessageWriter::class);

        $message = $writer->post($owner, $organization, $conversation, [
            'operation_id' => $operationId,
            'content' => 'Human confirmed text only',
        ]);
        $conversation->update(['status' => AiCommonConversation::STATUS_ARCHIVED, 'archived_at' => now()]);
        $replayed = $writer->post($owner, $organization, $conversation->fresh(), [
            'operation_id' => $operationId,
            'content' => 'Human confirmed text only',
        ]);

        $this->assertSame($message->id, $replayed->id);
        $this->assertSame('user', $message->role);
        $this->assertSame(0, $this->provider->calls);
        $this->assertDatabaseCount('ai_common_messages', 1);
        $this->assertDatabaseCount('ai_common_input_operations', 1);
        $this->assertSame(2, $conversation->fresh()->version);

        $this->expectException(ValidationException::class);
        $writer->post($owner, $organization, $conversation, [
            'operation_id' => $operationId,
            'content' => 'Different content',
        ]);
    }

    public function test_human_message_binds_only_current_ready_private_attachments(): void
    {
        [$owner, $member, $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = app(AiCommonAttachmentWriter::class)->reserveUpload($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(),
            'display_name' => 'safe.png',
            'mime_type' => 'image/png',
            'size_bytes' => 100,
        ]);
        Storage::disk('ai_common_attachments')->put($attachment->storage_key, 'verified-by-p2-boundary');
        $attachment->update([
            'state' => AiCommonAttachment::STATE_READY,
            'sha256' => hash('sha256', 'verified-by-p2-boundary'),
            'inspection_status' => 'passed',
            'inspection_driver' => 'p2-test',
            'inspection_version' => '1',
            'inspected_at_utc' => now('UTC'),
            'ready_at_utc' => now('UTC'),
        ]);
        $message = app(AiCommonHumanMessageWriter::class)->post($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(),
            'content' => 'Message with a verified attachment',
            'attachment_ids' => [$attachment->id],
        ]);

        $this->assertSame([$attachment->id], $message->attachments()->pluck('ai_common_attachments.id')->all());
        $this->assertSame(1, $message->attachments()->first()->pivot->attachment_version);
        $this->assertSame(0, $this->provider->calls);

        $this->expectException(AuthorizationException::class);
        app(AiCommonHumanMessageWriter::class)->post($member, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(),
            'content' => 'Private bypass',
            'attachment_ids' => [$attachment->id],
        ]);
    }

    public function test_revoke_is_idempotent_and_remains_safe_after_archive(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $writer = app(AiCommonAttachmentWriter::class);
        $attachment = $writer->reserveUpload($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(),
            'display_name' => 'revoke.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
        ]);
        $conversation->update(['status' => AiCommonConversation::STATUS_ARCHIVED, 'archived_at' => now()]);
        $operationId = (string) Str::uuid();
        $input = ['operation_id' => $operationId, 'reason' => 'No longer needed'];

        $revoked = $writer->revoke($owner, $organization, $conversation->fresh(), $attachment, $input);
        $replayed = $writer->revoke($owner, $organization, $conversation->fresh(), $attachment, $input);

        $this->assertSame($revoked->id, $replayed->id);
        $this->assertSame(AiCommonAttachment::STATE_REVOKED, $revoked->state);
        $this->assertSame(2, $revoked->version);
        $this->assertDatabaseCount('ai_common_attachment_operations', 2);
    }

    public function test_archived_conversation_rejects_attachment_and_human_message_changes(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $conversation->update(['status' => AiCommonConversation::STATUS_ARCHIVED, 'archived_at' => now()]);

        try {
            app(AiCommonAttachmentWriter::class)->reserveUpload($owner, $organization, $conversation->fresh(), [
                'operation_id' => (string) Str::uuid(),
                'display_name' => 'archived.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 100,
            ]);
            $this->fail('An archived conversation accepted an attachment reservation.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_common_attachments', 0);
        }

        $this->expectException(ValidationException::class);
        app(AiCommonHumanMessageWriter::class)->post($owner, $organization, $conversation->fresh(), [
            'operation_id' => (string) Str::uuid(),
            'content' => 'Archived message',
        ]);
    }

    private function conversation(User $owner, Organization $organization): AiCommonConversation
    {
        return AiCommonConversation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
            'title' => 'Delta A P1 private conversation',
            'status' => AiCommonConversation::STATUS_ACTIVE,
            'version' => 1,
        ]);
    }

    private function tenant(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Delta A P1 Synthetic',
            'slug' => 'delta-a-p1-'.Str::lower((string) Str::ulid()),
        ]);
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Delta A P1',
            'slug' => 'delta-a-p1-'.Str::lower((string) Str::ulid()),
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
                'classification_version' => 'delta-a-p1-test',
                'classified_at' => now(),
                'evidence_ref' => 'delta-a-p1-test',
            ]);
        }
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id,
            'is_enabled' => true,
            'allowed_categories' => ['common_entry'],
            'version' => 1,
            'managed_by_user_id' => $owner->id,
            'confirmed_at' => now(),
        ]);
        WorkspaceAiSetting::query()->create([
            'workspace_id' => $workspace->id,
            'enabled' => true,
            'provider' => 'member_managed_ai',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
            'terms_version' => WorkspaceAiSetting::TERMS_VERSION,
            'enabled_by' => $owner->id,
            'enabled_at' => now(),
        ]);

        return [$owner, $member, $organization, $workspace];
    }
}

class S11DeltaAP1Provider implements AiCommonProvider
{
    public int $calls = 0;

    public function respond(array $messages, array $sources): array
    {
        $this->calls++;

        return [
            'answer' => 'Provider must not be called by Human Message Writer.',
            'citations' => [],
            'provider' => 'unexpected',
            'model' => 'unexpected',
            'input_tokens' => null,
            'output_tokens' => null,
        ];
    }
}
