<?php

declare(strict_types=1);

use App\Models\AiCommonAttachment;
use App\Models\AiCommonConversation;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = $argv[1] ?? '';
$storage = $argv[2] ?? '';
$temp = realpath(sys_get_temp_dir());
$databaseParent = $database === '' ? false : realpath(dirname($database));
$storageParent = $storage === '' ? false : realpath(dirname($storage));

if ($temp === false
    || $databaseParent !== $temp
    || $storageParent !== $temp
    || ! str_starts_with(basename($database), 'company-os-s11cd-a-p5-')
    || ! str_starts_with(basename($storage), 'company-os-s11cd-a-p5-')) {
    fwrite(STDERR, "Refusing non-P5 temporary paths.\n");
    exit(64);
}
if (is_file($database)) {
    unlink($database);
}
if (file_exists($storage)) {
    fwrite(STDERR, "Refusing an existing P5 storage path.\n");
    exit(65);
}
touch($database);
foreach (['app/private/ai-common-attachments', 'app/private/ai-common-temporary-audio', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $directory) {
    mkdir($storage.'/'.$directory, 0777, true);
}

putenv('LARAVEL_STORAGE_PATH='.$storage);
$_ENV['LARAVEL_STORAGE_PATH'] = $storage;
$_SERVER['LARAVEL_STORAGE_PATH'] = $storage;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'testing',
    'app.url' => getenv('APP_URL') ?: 'http://127.0.0.1:18773',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
    'product_ux.organization_admission_enabled' => false,
]);
DB::purge('sqlite');
Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

$owner = User::create([
    'name' => 'Delta A Device Tester',
    'email' => 's11cd-a-p5-owner@example.test',
    'email_verified_at' => now(),
    'password' => Hash::make('not-used'),
    'is_active' => true,
]);
$member = User::create([
    'name' => 'Delta A Other Member',
    'email' => 's11cd-a-p5-member@example.test',
    'email_verified_at' => now(),
    'password' => Hash::make('not-used'),
    'is_active' => true,
]);
$organization = Organization::create([
    'name' => 'S11 Delta A P5 Device Verification',
    'slug' => 's11cd-a-p5-'.Str::lower((string) Str::ulid()),
]);
foreach ([[$owner, 'owner'], [$member, 'member']] as [$user, $role]) {
    OrganizationUser::create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
        'organization_role' => $role,
        'membership_status' => OrganizationUser::STATUS_ACTIVE,
        'access_epoch' => 1,
        'lifecycle_version' => 1,
        'permissions' => [],
        'joined_at' => now(),
    ]);
    ProductAccountEligibility::create([
        'user_id' => $user->id,
        'mode' => ProductAccountEligibility::MODE_SINGLE,
        'product_organization_id' => $organization->id,
        'classification_version' => 's11cd-a-p5-browser',
        'classified_at' => now(),
        'evidence_ref' => 's11cd-a-p5-browser',
    ]);
}
$workspace = Workspace::create([
    'organization_id' => $organization->id,
    'owner_user_id' => $owner->id,
    'name' => 'Delta A Device Verification',
    'slug' => 's11cd-a-p5-device',
    'type' => Workspace::TYPE_SHARED,
    'status' => Workspace::STATUS_ACTIVE,
]);
foreach ([$owner, $member] as $index => $user) {
    $workspace->users()->attach($user->id, ['role' => $index === 0 ? 'owner' : 'member', 'joined_at' => now()]);
}
OrganizationAiPolicy::create([
    'organization_id' => $organization->id,
    'is_enabled' => true,
    'allows_transcription' => true,
    'allowed_categories' => [OrganizationAiPolicy::CATEGORY_COMMON, OrganizationAiPolicy::CATEGORY_ATTACHMENT],
    'version' => 1,
    'managed_by_user_id' => $owner->id,
    'confirmed_at' => now(),
]);
$conversation = AiCommonConversation::create([
    'organization_id' => $organization->id,
    'user_id' => $owner->id,
    'title' => 'Delta A P5 Device Verification',
    'status' => AiCommonConversation::STATUS_ACTIVE,
    'version' => 1,
]);

$sampleRate = 8000;
$samples = str_repeat("\0\0", $sampleRate);
$wav = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $sampleRate, $sampleRate * 2, 2, 16).'data'.pack('V', strlen($samples)).$samples;
$attachmentPublicId = (string) Str::ulid();
$storageKey = $organization->public_id.'/'.$conversation->public_id.'/'.$attachmentPublicId.'.wav';
Storage::disk('ai_common_attachments')->put($storageKey, $wav);
$attachment = AiCommonAttachment::create([
    'public_id' => $attachmentPublicId,
    'organization_id' => $organization->id,
    'ai_common_conversation_id' => $conversation->id,
    'uploaded_by_user_id' => $owner->id,
    'variant' => AiCommonAttachment::VARIANT_UPLOAD,
    'state' => AiCommonAttachment::STATE_READY,
    'version' => 1,
    'display_name' => 'synthetic-playback.wav',
    'mime_type' => 'audio/wav',
    'extension' => 'wav',
    'size_bytes' => strlen($wav),
    'sha256' => hash('sha256', $wav),
    'storage_key' => $storageKey,
    'inspection_status' => 'passed',
    'inspection_driver' => 'p5-synthetic-fixture',
    'inspection_version' => '1',
    'inspected_at_utc' => now('UTC'),
    'media_codec' => 'pcm_s16le',
    'duration_ms' => 1000,
    'allows_ai_reference' => false,
    'ai_reference_version' => 1,
    'uploader_access_epoch' => 1,
    'uploader_credential_generation' => 1,
    'ready_at_utc' => now('UTC'),
]);

echo json_encode([
    'database' => $database,
    'storage' => $storage,
    'migration_count' => DB::table('migrations')->count(),
    'owner_email' => $owner->email,
    'member_email' => $member->email,
    'password' => 'not-used',
    'conversation_url' => route('ai-common.show', $conversation, false),
    'playback_url' => route('ai-common.attachments.playback', [$conversation, $attachment], false),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
