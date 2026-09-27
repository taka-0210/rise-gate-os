<?php

namespace Tests\Feature;

use App\Contracts\AiCommonProvider;
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
use App\Services\AiCommon\AiCommonAttachmentDeriver;
use App\Services\AiCommon\AiCommonAttachmentTranscriptWriter;
use App\Services\AiCommon\AiCommonAttachmentWriter;
use App\Services\AiCommon\AiCommonConversationReader;
use App\Services\AiCommon\AiCommonExtractionException;
use App\Services\AiCommon\AiCommonSourceManifest;
use App\Services\AiCommon\BoundedAiCommonAttachmentExtractor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class S11CompanionDeltaAP3Test extends TestCase
{
    use RefreshDatabase;

    private P3ChatProvider $chat;

    private P3TranscriptionProvider $transcription;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ai_common_attachments');
        $this->chat = new P3ChatProvider;
        $this->transcription = new P3TranscriptionProvider;
        $this->app->instance(AiCommonProvider::class, $this->chat);
        $this->app->instance(AiCommonTranscriptionProvider::class, $this->transcription);
    }

    public function test_pdf_docx_and_xlsx_extraction_is_explicit_and_bounded(): void
    {
        $extractor = app(BoundedAiCommonAttachmentExtractor::class);

        $pdf = $extractor->extract($this->pdf('P3 bounded PDF'), 'pdf', ['page_from' => 1, 'page_to' => 1]);
        $docx = $extractor->extract($this->docx(['first paragraph', 'second paragraph']), 'docx', [
            'paragraph_from' => 2, 'paragraph_to' => 2,
        ]);
        $xlsx = $extractor->extract($this->xlsx(), 'xlsx', [
            'sheet_index' => 1, 'row_from' => 2, 'row_to' => 2,
        ]);

        $this->assertStringContainsString('P3 bounded PDF', $pdf['content']);
        $this->assertSame('second paragraph', $docx['content']);
        $this->assertStringContainsString('A2=bounded cell', $xlsx['content']);
        $this->assertStringNotContainsString('A1=excluded cell', $xlsx['content']);
    }

    public function test_unsupported_corrupt_and_overbroad_extraction_fail_closed(): void
    {
        $extractor = app(BoundedAiCommonAttachmentExtractor::class);
        foreach ([
            fn () => $extractor->extract('png', 'png', []),
            fn () => $extractor->extract('corrupt', 'docx', ['paragraph_from' => 1, 'paragraph_to' => 1]),
            fn () => $extractor->extract($this->docx(['one']), 'docx', ['paragraph_from' => 1, 'paragraph_to' => 51]),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Unreadable or overbroad content was accepted.');
            } catch (AiCommonExtractionException $error) {
                $this->assertNotSame('', $error->safeCode);
            }
        }
    }

    public function test_extraction_persists_immutable_derivative_without_ai_request_and_preview_is_private(): void
    {
        [$owner, $member, $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'docx', $this->docx(['<script>unsafe()</script>']));
        $operationId = (string) Str::uuid();
        $derivative = app(AiCommonAttachmentDeriver::class)->extract(
            $owner, $organization, $conversation, $attachment, $operationId,
            ['paragraph_from' => 1, 'paragraph_to' => 1],
        );
        $replay = app(AiCommonAttachmentDeriver::class)->extract(
            $owner, $organization, $conversation, $attachment, $operationId,
            ['paragraph_from' => 1, 'paragraph_to' => 1],
        );

        $this->assertSame(AiCommonAttachmentDerivative::STATE_READY, $derivative->state);
        $this->assertSame($derivative->id, $replay->id);
        $this->assertSame('<script>unsafe()</script>', $derivative->content);
        $this->assertSame(0, $this->chat->calls);
        $session = $this->companySession($organization);
        $this->actingAs($owner)->withSession($session)
            ->get(route('ai-common.attachments.preview', [$conversation, $attachment, $derivative]))
            ->assertOk()->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertHeader('cache-control', 'max-age=0, no-store, private');
        $this->actingAs($member)->withSession($session)
            ->get(route('ai-common.attachments.preview', [$conversation, $attachment, $derivative]))
            ->assertForbidden();
        $image = $this->attachment($owner, $organization, $conversation, 'png', "\x89PNG\r\n\x1a\nsynthetic", 'image/png');
        $this->actingAs($owner)->withSession($session)
            ->get(route('ai-common.attachments.image-preview', [$conversation, $image]))
            ->assertOk()->assertHeader('content-type', 'image/png')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_audio_playback_transcript_and_human_revision_are_private_immutable_and_separate_from_chat(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'webm', 'synthetic-audio', 'audio/webm', 60_000);
        $operationId = (string) Str::uuid();
        $revision = app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
            $owner, $organization, $conversation, $attachment, $operationId, true,
        );
        $replay = app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
            $owner, $organization, $conversation, $attachment, $operationId, true,
        );
        $humanOperationId = (string) Str::uuid();
        $human = app(AiCommonAttachmentTranscriptWriter::class)->revise(
            $owner, $organization, $conversation, $attachment, $revision, $humanOperationId, 'Human corrected transcript',
        );
        $humanReplay = app(AiCommonAttachmentTranscriptWriter::class)->revise(
            $owner, $organization, $conversation, $attachment, $revision, $humanOperationId, 'Human corrected transcript',
        );

        $this->assertSame($revision->id, $replay->id);
        $this->assertSame('provider transcript', $revision->content);
        $this->assertSame($revision->id, $human->parent_revision_id);
        $this->assertSame($human->id, $humanReplay->id);
        $this->assertSame('provider transcript', $revision->fresh()->content);
        $this->assertSame(1, $this->transcription->calls);
        $this->assertSame(0, $this->chat->calls);
        $this->assertDatabaseCount('ai_common_messages', 0);
        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->get(route('ai-common.attachments.playback', [$conversation, $attachment]))
            ->assertOk()->assertHeader('accept-ranges', 'bytes')
            ->assertHeader('cache-control', 'max-age=0, no-store, private');
        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->withHeader('Range', 'bytes=0-3')
            ->get(route('ai-common.attachments.playback', [$conversation, $attachment]))
            ->assertStatus(206)->assertHeader('content-range', 'bytes 0-3/15')
            ->assertContent('synt');
    }

    public function test_attachment_source_uses_only_bounded_projection_and_revoke_propagates_to_history(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'docx', $this->docx(['bounded secret']));
        $derivative = app(AiCommonAttachmentDeriver::class)->extract(
            $owner, $organization, $conversation, $attachment, (string) Str::uuid(),
            ['paragraph_from' => 1, 'paragraph_to' => 1],
        );
        app(AiCommonAttachmentWriter::class)->setAiReference(
            $owner, $organization, $conversation, $attachment,
            ['operation_id' => (string) Str::uuid(), 'enabled' => true],
        );
        $source = app(AiCommonSourceManifest::class)->select(
            $owner, $organization, $conversation, 'attachment_extract', $derivative->public_id, 'P3 bounded source',
        );
        $authorized = app(AiCommonSourceManifest::class)->authorizeRevision($owner, $organization, $source->currentRevision);

        $this->assertSame('bounded secret', $authorized['data']['content']);
        $encoded = json_encode($authorized);
        $this->assertStringNotContainsString($attachment->display_name, $encoded);
        $this->assertStringNotContainsString($attachment->storage_key, $encoded);
        $message = $conversation->messages()->create([
            'role' => AiCommonMessage::ROLE_ASSISTANT,
            'content' => 'Derived answer',
            'visibility_status' => AiCommonMessage::VISIBILITY_VISIBLE,
            'source_lineage_version' => AiCommonMessage::SOURCE_LINEAGE_V1,
        ]);
        $message->sourceRevisions()->sync([$source->current_revision_id]);
        app(AiCommonAttachmentWriter::class)->revoke(
            $owner, $organization, $conversation, $attachment,
            ['operation_id' => (string) Str::uuid(), 'reason' => 'P3 revoke evidence'],
        );
        $rows = app(AiCommonConversationReader::class)->visible($owner, $organization, $conversation);
        $context = app(AiCommonConversationReader::class)->providerContext($owner, $organization, $conversation);
        $this->assertFalse($rows[0]['visible']);
        $this->assertSame([], $context['messages']);
        $this->assertCount(0, $context['revisions']);
    }

    public function test_source_requires_uploader_opt_in_category_and_same_conversation(): void
    {
        [$owner, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $other = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'docx', $this->docx(['private']));
        $derivative = app(AiCommonAttachmentDeriver::class)->extract(
            $owner, $organization, $conversation, $attachment, (string) Str::uuid(),
            ['paragraph_from' => 1, 'paragraph_to' => 1],
        );
        try {
            app(AiCommonSourceManifest::class)->select(
                $owner, $organization, $conversation, 'attachment_extract', $derivative->public_id, 'no opt in',
            );
            $this->fail('Attachment source was selected without uploader opt-in.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        app(AiCommonAttachmentWriter::class)->setAiReference(
            $owner, $organization, $conversation, $attachment,
            ['operation_id' => (string) Str::uuid(), 'enabled' => true],
        );
        $this->expectException(AuthorizationException::class);
        app(AiCommonSourceManifest::class)->select(
            $owner, $organization, $other, 'attachment_extract', $derivative->public_id, 'cross conversation',
        );
    }

    public function test_provider_result_is_discarded_when_attachment_is_revoked_during_transcription(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'webm', 'late-audio', 'audio/webm', 20_000);
        $this->transcription->duringCall = function () use ($owner, $organization, $conversation, $attachment): void {
            app(AiCommonAttachmentWriter::class)->revoke(
                $owner, $organization, $conversation, $attachment->fresh(),
                ['operation_id' => (string) Str::uuid(), 'reason' => 'revoked during provider I/O'],
            );
        };

        try {
            app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
                $owner, $organization, $conversation, $attachment, (string) Str::uuid(), true,
            );
            $this->fail('Late transcript was accepted after revoke.');
        } catch (AuthorizationException|ValidationException) {
            $this->assertDatabaseCount('ai_common_transcript_revisions', 0);
            $this->assertDatabaseHas('ai_common_attachment_transcription_operations', [
                'result_status' => 'failed',
                'safe_error_code' => 'transcription_authorization_changed',
            ]);
        }
    }

    public function test_archived_conversation_blocks_new_p3_derivatives_and_ui_never_autoplays(): void
    {
        [$owner, , $organization] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $attachment = $this->attachment($owner, $organization, $conversation, 'webm', 'audio', 'audio/webm', 1000);
        $this->actingAs($owner)->withSession($this->companySession($organization))
            ->get(route('ai-common.show', $conversation))
            ->assertOk()->assertSee('preload="none"', false)->assertDontSee('autoplay', false);
        $conversation->update(['status' => AiCommonConversation::STATUS_ARCHIVED, 'archived_at' => now()]);

        $this->expectException(ValidationException::class);
        app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
            $owner, $organization, $conversation->fresh(), $attachment, (string) Str::uuid(), true,
        );
    }

    private function attachment(User $owner, Organization $organization, AiCommonConversation $conversation, string $extension, string $binary, ?string $mime = null, ?int $duration = null): AiCommonAttachment
    {
        $publicId = (string) Str::ulid();
        $key = $organization->public_id.'/'.$conversation->public_id.'/'.$publicId;
        Storage::disk('ai_common_attachments')->put($key, $binary);

        return AiCommonAttachment::query()->create([
            'public_id' => $publicId, 'organization_id' => $organization->id,
            'ai_common_conversation_id' => $conversation->id, 'uploaded_by_user_id' => $owner->id,
            'variant' => AiCommonAttachment::VARIANT_UPLOAD, 'state' => AiCommonAttachment::STATE_READY,
            'version' => 2, 'display_name' => 'private-source.'.$extension,
            'mime_type' => $mime ?? match ($extension) {
                'pdf' => 'application/pdf',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                default => 'application/octet-stream',
            },
            'extension' => $extension, 'size_bytes' => strlen($binary), 'sha256' => hash('sha256', $binary),
            'storage_key' => $key, 'inspection_status' => 'passed', 'inspection_driver' => 'p3-test',
            'inspection_version' => '1', 'duration_ms' => $duration, 'media_codec' => $duration ? 'opus' : null,
            'allows_ai_reference' => false, 'ai_reference_version' => 1,
            'uploader_access_epoch' => 1, 'uploader_credential_generation' => 1,
            'ready_at_utc' => now('UTC'), 'inspected_at_utc' => now('UTC'),
        ]);
    }

    private function conversation(User $owner, Organization $organization): AiCommonConversation
    {
        return AiCommonConversation::query()->create([
            'organization_id' => $organization->id, 'user_id' => $owner->id,
            'title' => 'Delta A P3 private conversation', 'status' => 'active', 'version' => 1,
        ]);
    }

    private function tenant(bool $transcription = false): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create([
            'name' => 'Delta A P3 Synthetic', 'slug' => 'delta-a-p3-'.Str::lower((string) Str::ulid()),
        ]);
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id, 'owner_user_id' => $owner->id,
            'name' => 'Delta A P3', 'slug' => 'delta-a-p3-'.Str::lower((string) Str::ulid()),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        foreach ([[$owner, 'owner'], [$member, 'member']] as [$user, $role]) {
            $organization->users()->attach($user->id, [
                'role' => $role, 'organization_role' => $role, 'membership_status' => 'active',
                'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now(),
            ]);
            $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
            ProductAccountEligibility::query()->create([
                'user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
                'product_organization_id' => $organization->id, 'classification_version' => 'p3-test',
                'classified_at' => now(), 'evidence_ref' => 'p3-test',
            ]);
        }
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id, 'is_enabled' => true,
            'allows_transcription' => $transcription,
            'allowed_categories' => ['common_entry', 'attachment'],
            'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);

        return [$owner, $member, $organization, $workspace];
    }

    private function companySession(Organization $organization): array
    {
        return [
            'access_mode' => 'workspace', 'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1, 'credential_generation' => 1,
        ];
    }

    private function docx(array $paragraphs): string
    {
        $body = implode('', array_map(
            fn (string $text): string => '<w:p><w:r><w:t>'.htmlspecialchars($text, ENT_XML1).'</w:t></w:r></w:p>',
            $paragraphs,
        ));

        return $this->zip([
            'word/document.xml' => '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'</w:body></w:document>',
        ]);
    }

    private function xlsx(): string
    {
        return $this->zip([
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>excluded cell</t></is></c></row><row r="2"><c r="A2" t="inlineStr"><is><t>bounded cell</t></is></c></row></sheetData></worksheet>',
        ]);
    }

    private function zip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'p3-zip-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $binary = file_get_contents($path);
        @unlink($path);

        return $binary;
    }

    private function pdf(string $text): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text).') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream).' >>'.PHP_EOL.'stream'.PHP_EOL.$stream.PHP_EOL.'endstream',
        ];
        $pdf = '%PDF-1.4'.PHP_EOL;
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1).' 0 obj'.PHP_EOL.$object.PHP_EOL.'endobj'.PHP_EOL;
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'.PHP_EOL.'0 6'.PHP_EOL.'0000000000 65535 f '.PHP_EOL;
        for ($index = 1; $index <= 5; $index++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$index]).PHP_EOL;
        }

        return $pdf.'trailer'.PHP_EOL.'<< /Size 6 /Root 1 0 R >>'.PHP_EOL.'startxref'.PHP_EOL.$xref.PHP_EOL.'%%EOF'.PHP_EOL;
    }
}

class P3ChatProvider implements AiCommonProvider
{
    public int $calls = 0;

    public function respond(array $messages, array $sources): array
    {
        $this->calls++;

        return [
            'answer' => 'unexpected', 'citations' => [], 'provider' => 'test', 'model' => 'test',
            'input_tokens' => null, 'output_tokens' => null,
        ];
    }
}

class P3TranscriptionProvider implements AiCommonTranscriptionProvider
{
    public int $calls = 0;

    public ?\Closure $duringCall = null;

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        $this->calls++;
        ($this->duringCall) && ($this->duringCall)();

        return ['text' => 'provider transcript', 'provider' => 'test', 'model' => 'transcribe-test'];
    }
}
