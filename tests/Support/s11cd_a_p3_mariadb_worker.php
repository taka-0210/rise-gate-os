<?php

declare(strict_types=1);

use App\Contracts\AiCommonAttachmentExtractor;
use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCommon\AiCommonAttachmentDeriver;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$version = (string) DB::selectOne('select version() as value')->value;
$datadir = str_replace('\\', '/', (string) DB::selectOne('select @@datadir as value')->value);
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 13349
    || config('database.connections.mysql.database') !== 'co_s11cd_a_p3_concurrency'
    || ! str_starts_with($version, '10.11.')
    || ! str_contains($datadir, '/company-os-s11cd-a-p3-mariadb-10.11.19-concurrency-')) {
    throw new RuntimeException('S11-CD-A P3 MariaDB guard rejected target.');
}
$storageRoot = (string) env('P3_ATTACHMENT_ROOT');
if ($storageRoot === '' || ! str_contains(str_replace('\\', '/', $storageRoot), '/company-os-s11cd-a-p3-mariadb-10.11.19-concurrency-')) {
    throw new RuntimeException('S11-CD-A P3 attachment root guard rejected target.');
}
config(['filesystems.disks.ai_common_attachments.root' => $storageRoot]);

app()->instance(AiCommonAttachmentExtractor::class, new class implements AiCommonAttachmentExtractor
{
    public function extract(string $binary, string $extension, array $selector): array
    {
        return [
            'content' => 'Independent worker bounded content',
            'selector' => $selector,
            'driver' => 'mariadb-concurrency-fixture',
            'version' => 'p3-v1',
        ];
    }
});

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $owner = User::create([
        'name' => 'P3 Owner', 'email' => 'p3-owner@example.test',
        'password' => Hash::make('isolated'), 'is_active' => true,
    ]);
    $organization = Organization::create(['name' => 'P3 Isolated', 'slug' => 'p3-isolated']);
    $workspace = Workspace::create([
        'organization_id' => $organization->id, 'owner_user_id' => $owner->id,
        'name' => 'P3', 'slug' => 'p3', 'status' => Workspace::STATUS_ACTIVE,
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
        'classification_version' => 'p3-mariadb', 'classified_at' => now(),
        'evidence_ref' => 'p3-mariadb',
    ]);
    OrganizationAiPolicy::create([
        'organization_id' => $organization->id, 'is_enabled' => true,
        'allowed_categories' => ['common_entry', 'attachment'],
        'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
    ]);
    $conversation = AiCommonConversation::create([
        'organization_id' => $organization->id, 'user_id' => $owner->id,
        'title' => 'P3 concurrency', 'status' => 'active', 'version' => 1,
    ]);
    $binary = 'synthetic-p3-attachment';
    $key = $organization->public_id.'/'.$conversation->public_id.'/concurrency';
    Storage::disk('ai_common_attachments')->put($key, $binary);
    AiCommonAttachment::create([
        'organization_id' => $organization->id,
        'ai_common_conversation_id' => $conversation->id,
        'uploaded_by_user_id' => $owner->id,
        'variant' => AiCommonAttachment::VARIANT_UPLOAD,
        'state' => AiCommonAttachment::STATE_READY,
        'version' => 2, 'display_name' => 'concurrency.docx',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'extension' => 'docx', 'size_bytes' => strlen($binary),
        'sha256' => hash('sha256', $binary), 'storage_key' => $key,
        'inspection_status' => 'passed', 'inspection_driver' => 'fixture',
        'inspection_version' => '1', 'allows_ai_reference' => false,
        'ai_reference_version' => 1, 'uploader_access_epoch' => 1,
        'uploader_credential_generation' => 1,
        'ready_at_utc' => now('UTC'), 'inspected_at_utc' => now('UTC'),
    ]);
    echo 'seeded';
    exit(0);
}

if ($mode === 'derive') {
    $barrier = $argv[2] ?? '';
    $operationId = $argv[3] ?? '';
    while (! is_file($barrier)) {
        usleep(20000);
    }
    $owner = User::where('email', 'p3-owner@example.test')->firstOrFail();
    $organization = Organization::where('slug', 'p3-isolated')->firstOrFail();
    $conversation = AiCommonConversation::where('title', 'P3 concurrency')->firstOrFail();
    $attachment = AiCommonAttachment::where('display_name', 'concurrency.docx')->firstOrFail();
    $result = app(AiCommonAttachmentDeriver::class)->extract(
        $owner, $organization, $conversation, $attachment, $operationId,
        ['paragraph_from' => 1, 'paragraph_to' => 1],
    );
    echo $result->public_id;
    exit(0);
}

if ($mode === 'inspect') {
    echo json_encode([
        'derivatives' => DB::table('ai_common_attachment_derivatives')->count(),
        'ready' => DB::table('ai_common_attachment_derivatives')->where('state', 'ready')->count(),
        'operations' => DB::table('ai_common_attachment_derivatives')->distinct()->count('operation_id'),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException('Unknown P3 worker mode.');
