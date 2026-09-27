<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = $argv[1] ?? '';
$storage = $argv[2] ?? '';
$password = getenv('S11CD_B_P5_DEVICE_PASSWORD') ?: '';
$temp = realpath(sys_get_temp_dir());
$databaseParent = $database === '' ? false : realpath(dirname($database));
$storageParent = $storage === '' ? false : realpath(dirname($storage));

if ($temp === false
    || $databaseParent !== $temp
    || $storageParent !== $temp
    || ! str_starts_with(basename($database), 'company-os-s11cd-b-p5-')
    || ! str_starts_with(basename($storage), 'company-os-s11cd-b-p5-')
    || strlen($password) < 12) {
    fwrite(STDERR, "Refusing unsafe B-P5 device fixture configuration.\n");
    exit(64);
}
if (is_file($database)) {
    unlink($database);
}
if (file_exists($storage)) {
    fwrite(STDERR, "Refusing an existing B-P5 storage path.\n");
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
    'app.url' => getenv('APP_URL') ?: 'http://127.0.0.1:18776',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
    'product_ux.organization_admission_enabled' => false,
]);
DB::purge('sqlite');
Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

$owner = User::create([
    'name' => 'Delta B Device Tester',
    'email' => 's11cd-b-p5-owner@example.test',
    'email_verified_at' => now(),
    'password' => Hash::make($password),
    'is_active' => true,
]);
$organization = Organization::create([
    'name' => 'S11 Delta B P5 Device Verification',
    'slug' => 's11cd-b-p5-'.Str::lower((string) Str::ulid()),
]);
OrganizationUser::create([
    'organization_id' => $organization->id,
    'user_id' => $owner->id,
    'role' => 'owner',
    'organization_role' => 'owner',
    'membership_status' => OrganizationUser::STATUS_ACTIVE,
    'access_epoch' => 1,
    'lifecycle_version' => 1,
    'permissions' => [],
    'joined_at' => now(),
]);
ProductAccountEligibility::create([
    'user_id' => $owner->id,
    'mode' => ProductAccountEligibility::MODE_SINGLE,
    'product_organization_id' => $organization->id,
    'classification_version' => 's11cd-b-p5-browser',
    'classified_at' => now(),
    'evidence_ref' => 's11cd-b-p5-browser',
]);
$workspace = Workspace::create([
    'organization_id' => $organization->id,
    'owner_user_id' => $owner->id,
    'name' => 'Delta B Device Verification',
    'slug' => 's11cd-b-p5-device',
    'type' => Workspace::TYPE_SHARED,
    'status' => Workspace::STATUS_ACTIVE,
]);
$workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
OrganizationAiPolicy::create([
    'organization_id' => $organization->id,
    'is_enabled' => true,
    'allows_transcription' => true,
    'allowed_categories' => [OrganizationAiPolicy::CATEGORY_COMMON, OrganizationAiPolicy::CATEGORY_ATTACHMENT],
    'version' => 1,
    'managed_by_user_id' => $owner->id,
    'confirmed_at' => now(),
]);
$conversation = app(AiCommonSharedConversationWriter::class)->create($owner, $organization, [
    'operation_id' => (string) Str::uuid(),
    'name' => 'Delta B P5 Shared Device Verification',
    'purpose' => 'Verify Shared-room, One Shared CO, bounded Transcript, Presence, and multi-device view.',
]);

echo json_encode([
    'database' => $database,
    'storage' => $storage,
    'migration_count' => DB::table('migrations')->count(),
    'owner_email' => $owner->email,
    'conversation_url' => route('ai-common.shared.show', $conversation, false),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;

