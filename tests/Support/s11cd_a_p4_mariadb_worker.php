<?php

declare(strict_types=1);

use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCommon\AiCommonAttachmentTranscriptWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$version = (string) DB::selectOne('select version() as value')->value;
$datadir = str_replace('\\', '/', (string) DB::selectOne('select @@datadir as value')->value);
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 13349
    || config('database.connections.mysql.database') !== 'company_os_s11cd_a_p4'
    || ! str_starts_with($version, '10.11.19-')
    || ! str_contains($datadir, '/company-os-s11cd-a-p4-mariadb-10.11.19-')) {
    throw new RuntimeException('S11-CD-A P4 MariaDB guard rejected target.');
}
$storageRoot = (string) env('P4_ATTACHMENT_ROOT');
if ($storageRoot === '' || ! str_contains(str_replace('\\', '/', $storageRoot), '/company-os-s11cd-a-p4-mariadb-10.11.19-')) {
    throw new RuntimeException('S11-CD-A P4 attachment root guard rejected target.');
}
config(['filesystems.disks.ai_common_attachments.root' => $storageRoot]);

app()->instance(AiCommonTranscriptionProvider::class, new class($storageRoot) implements AiCommonTranscriptionProvider
{
    public function __construct(private readonly string $storageRoot) {}

    public function transcribe(string $binary, string $mimeType, string $extension): array
    {
        file_put_contents($this->storageRoot.'/provider-'.getmypid().'.marker', 'called', LOCK_EX);
        usleep(500000);

        return [
            'text' => 'Independent process transcript',
            'provider' => 'p4-concurrency-fixture',
            'model' => 'p4-v1',
            'usage' => ['unit' => 'audio_tokens', 'quantity' => 7],
        ];
    }
});

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $owner = User::create([
        'name' => 'P4 Owner', 'email' => 'p4-owner@example.test',
        'password' => Hash::make('isolated'), 'is_active' => true,
    ]);
    $organization = Organization::create(['name' => 'P4 Isolated', 'slug' => 'p4-isolated']);
    $workspace = Workspace::create([
        'organization_id' => $organization->id, 'owner_user_id' => $owner->id,
        'name' => 'P4', 'slug' => 'p4', 'status' => Workspace::STATUS_ACTIVE,
    ]);
    OrganizationUser::create([
        'organization_id' => $organization->id, 'user_id' => $owner->id,
        'role' => 'owner', 'organization_role' => 'owner',
        'membership_status' => OrganizationUser::STATUS_ACTIVE,
        'access_epoch' => 1, 'lifecycle_version' => 1, 'permissions' => [], 'joined_at' => now(),
    ]);
    $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
    ProductAccountEligibility::create([
        'user_id' => $owner->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
        'product_organization_id' => $organization->id,
        'classification_version' => 'p4-mariadb', 'classified_at' => now(),
        'evidence_ref' => 'p4-mariadb',
    ]);
    OrganizationAiPolicy::create([
        'organization_id' => $organization->id, 'is_enabled' => true,
        'allows_transcription' => true,
        'allowed_categories' => ['common_entry', 'attachment'],
        'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
    ]);
    $conversation = AiCommonConversation::create([
        'organization_id' => $organization->id, 'user_id' => $owner->id,
        'title' => 'P4 concurrency', 'status' => 'active', 'version' => 1,
    ]);
    $binary = 'synthetic-p4-audio';
    $key = $organization->public_id.'/'.$conversation->public_id.'/concurrency';
    Storage::disk('ai_common_attachments')->put($key, $binary);
    AiCommonAttachment::create([
        'organization_id' => $organization->id,
        'ai_common_conversation_id' => $conversation->id,
        'uploaded_by_user_id' => $owner->id,
        'variant' => AiCommonAttachment::VARIANT_UPLOAD,
        'state' => AiCommonAttachment::STATE_READY,
        'version' => 2, 'display_name' => 'concurrency.webm',
        'mime_type' => 'audio/webm', 'extension' => 'webm',
        'size_bytes' => strlen($binary), 'sha256' => hash('sha256', $binary),
        'storage_key' => $key, 'inspection_status' => 'passed',
        'inspection_driver' => 'fixture', 'inspection_version' => '1',
        'media_codec' => 'opus', 'duration_ms' => 15_000,
        'allows_ai_reference' => false, 'ai_reference_version' => 1,
        'uploader_access_epoch' => 1, 'uploader_credential_generation' => 1,
        'ready_at_utc' => now('UTC'), 'inspected_at_utc' => now('UTC'),
    ]);
    echo 'seeded';
    exit(0);
}

if ($mode === 'transcribe') {
    $barrier = $argv[2] ?? '';
    $operationId = $argv[3] ?? '';
    while (! is_file($barrier)) {
        usleep(20000);
    }
    $owner = User::where('email', 'p4-owner@example.test')->firstOrFail();
    $organization = Organization::where('slug', 'p4-isolated')->firstOrFail();
    $conversation = AiCommonConversation::where('title', 'P4 concurrency')->firstOrFail();
    $attachment = AiCommonAttachment::where('display_name', 'concurrency.webm')->firstOrFail();
    try {
        $revision = app(AiCommonAttachmentTranscriptWriter::class)->transcribe(
            $owner, $organization, $conversation, $attachment, $operationId, true,
        );
        echo 'success:'.$revision->public_id;
    } catch (ValidationException) {
        echo 'converged-without-provider';
    }
    exit(0);
}

if ($mode === 'inspect') {
    echo json_encode([
        'operations' => DB::table('ai_common_attachment_transcription_operations')->count(),
        'revisions' => DB::table('ai_common_transcript_revisions')->count(),
        'usage' => DB::table('ai_usage_ledgers')->where('purpose', 'transcription')->count(),
        'audit' => DB::table('ai_common_audit_events')->where('event', 'transcription.finalized')->count(),
        'provider_calls' => count(glob($storageRoot.'/provider-*.marker') ?: []),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException('Unknown P4 worker mode.');
