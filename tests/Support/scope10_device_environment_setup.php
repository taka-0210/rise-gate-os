<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationNotificationPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\UserNotificationPreference;
use App\Models\Workspace;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function scopeTenDeviceFail(string $message, int $code = 64): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($code);
}

$temp = realpath(sys_get_temp_dir());
$database = getenv('S10_DEVICE_DATABASE') ?: '';
$secretsPath = getenv('S10_DEVICE_SECRETS') ?: '';
$accountEmail = strtolower(trim(getenv('S10_DEVICE_ACCOUNT_EMAIL') ?: ''));
$emailRecipient = strtolower(trim(getenv('S10_DEVICE_EMAIL_RECIPIENT') ?: ''));

foreach ([$database, $secretsPath] as $path) {
    $parent = $path === '' ? false : realpath(dirname($path));
    if ($temp === false || $parent !== $temp || ! str_starts_with(basename($path), 'company-os-scope10-device-')) {
        scopeTenDeviceFail('Scope 10 device environment path guard rejected the target.');
    }
}
if (! filter_var($accountEmail, FILTER_VALIDATE_EMAIL) || ! filter_var($emailRecipient, FILTER_VALIDATE_EMAIL)) {
    scopeTenDeviceFail('Scope 10 device environment email guard rejected the target.');
}

if (! is_file($secretsPath)) {
    $vapid = VAPID::createVapidKeys();
    $secrets = [
        'app_key' => 'base64:'.base64_encode(random_bytes(32)),
        'password' => rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '='),
        'vapid_public_key' => $vapid['publicKey'],
        'vapid_private_key' => $vapid['privateKey'],
    ];
    if (file_put_contents($secretsPath, json_encode($secrets, JSON_THROW_ON_ERROR)) === false) {
        scopeTenDeviceFail('Could not create the Scope 10 device secret file.', 65);
    }
}

$secrets = json_decode((string) file_get_contents($secretsPath), true, flags: JSON_THROW_ON_ERROR);
putenv('APP_KEY='.$secrets['app_key']);
$_ENV['APP_KEY'] = $secrets['app_key'];
$_SERVER['APP_KEY'] = $secrets['app_key'];

if (is_file($database)) {
    unlink($database);
}
touch($database);

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'testing',
    'app.key' => $secrets['app_key'],
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $database,
    'product_ux.organization_admission_enabled' => false,
    'company_notifications.delivery_enabled' => false,
]);
DB::purge('sqlite');
Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

$organization = Organization::create(['name' => 'Scope 10 Device Verification', 'slug' => 'scope10-device-'.strtolower((string) Str::ulid())]);
$accounts = [
    ['Device Tester', $accountEmail, 'owner'],
    ['Event Sender', 'scope10-device-sender@example.test', 'member'],
    ['Switch Account', 'scope10-device-switch@example.test', 'member'],
    ['Email Recipient', $emailRecipient, 'member'],
];
$users = [];
foreach ($accounts as [$name, $email, $role]) {
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'email_verified_at' => now(),
        'password' => Hash::make($secrets['password']),
        'is_active' => true,
    ]);
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
        'classification_version' => 'scope10-device-close',
        'classified_at' => now(),
        'evidence_ref' => 'scope10-device-close-verification',
    ]);
    $users[] = $user;
}

$workspace = Workspace::create([
    'organization_id' => $organization->id,
    'owner_user_id' => $users[0]->id,
    'name' => 'Device Verification',
    'slug' => 'scope10-device',
    'type' => Workspace::TYPE_SHARED,
    'status' => Workspace::STATUS_ACTIVE,
]);
foreach ($users as $index => $user) {
    $workspace->users()->attach($user->id, ['role' => $index === 0 ? 'owner' : 'member', 'joined_at' => now()]);
}

$writer = app(ProjectExecutionWriter::class);
$project = $writer->createProject($users[0], $workspace, [
    'name' => 'Scope 10 Device Verification',
    'purpose' => 'Verify generic notification delivery',
    'expected_outcome' => 'No business data leaves the isolated environment',
]);
foreach (array_slice($users, 1) as $user) {
    $writer->addMember($users[0], $project->fresh(), $user, $workspace, ['member'], $project->fresh()->plan_version);
}

OrganizationNotificationPolicy::create([
    'organization_id' => $organization->id,
    'is_confirmed' => true,
    'timezone' => 'Asia/Tokyo',
    'updated_by_user_id' => $users[0]->id,
]);
UserNotificationPreference::create([
    'organization_id' => $organization->id,
    'user_id' => $users[3]->id,
    'in_app_enabled' => true,
    'push_enabled' => false,
    'email_enabled' => true,
    'email_fallback_enabled' => false,
]);

echo json_encode([
    'status' => 'ready',
    'database' => $database,
    'secrets' => $secretsPath,
    'organization_id' => $organization->id,
    'workspace_id' => $workspace->id,
    'project_id' => $project->id,
    'device_user_id' => $users[0]->id,
    'sender_user_id' => $users[1]->id,
    'switch_user_id' => $users[2]->id,
    'email_user_id' => $users[3]->id,
    'migration_count' => DB::table('migrations')->count(),
    'vapid_public_fingerprint' => hash('sha256', $secrets['vapid_public_key']),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
