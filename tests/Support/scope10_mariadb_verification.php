<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Notification\NotificationDeliveryProcessor;
use App\Services\Notification\NotificationSourceWriter;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function scopeTenFail(string $message, int $code = 64): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($code);
}

$mode = $argv[1] ?? '';
$host = getenv('S10_DB_HOST') ?: '';
$port = (int) (getenv('S10_DB_PORT') ?: 0);
$database = getenv('S10_DB_DATABASE') ?: '';
$expectedDatadir = str_replace('\\', '/', getenv('S10_EXPECTED_DATADIR') ?: '');

if ($host !== '127.0.0.1' || $port !== 13319 || ! preg_match('/\Aco_scope10_[a-z0-9_]+\z/', $database)
    || $expectedDatadir === '' || ! str_contains(strtolower($expectedDatadir), 'company-os-scope10-mariadb-10.11.19')) {
    scopeTenFail('Scope 10 MariaDB target guard rejected the environment.');
}

$root = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=mysql;charset=utf8mb4', $host, $port),
    getenv('S10_DB_USERNAME') ?: 'root',
    getenv('S10_DB_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
);
$identity = $root->query('SELECT VERSION() version, @@datadir datadir, @@character_set_server charset_name, @@collation_server collation_name, @@tx_isolation isolation_name')
    ->fetch(PDO::FETCH_ASSOC);
if (! str_starts_with((string) $identity['version'], '10.11.19-')
    || rtrim(str_replace('\\', '/', (string) $identity['datadir']), '/') !== rtrim($expectedDatadir, '/')) {
    scopeTenFail('Scope 10 MariaDB server identity mismatch.', 65);
}

if ($mode === 'create-schema') {
    $identifier = chr(96).$database.chr(96);
    $root->exec('CREATE DATABASE IF NOT EXISTS '.$identifier.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo json_encode(['status' => 'ready', ...$identity], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($mode === 'seed') {
    $owner = User::create(['name' => 'S10 Owner', 'email' => 's10-owner@example.test', 'email_verified_at' => now(), 'password' => Hash::make('not-used'), 'is_active' => true]);
    $assignee = User::create(['name' => 'S10 Assignee', 'email' => 's10-assignee@example.test', 'email_verified_at' => now(), 'password' => Hash::make('not-used'), 'is_active' => true]);
    $organization = Organization::create(['name' => 'Scope 10 Isolated', 'slug' => 'scope10-isolated']);
    $workspace = Workspace::create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id, 'name' => 'S10', 'slug' => 'scope10', 'status' => Workspace::STATUS_ACTIVE]);
    foreach ([[$owner, 'owner'], [$assignee, 'member']] as [$user, $role]) {
        OrganizationUser::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'organization_role' => $role,
            'membership_status' => OrganizationUser::STATUS_ACTIVE, 'access_epoch' => 1, 'lifecycle_version' => 1, 'permissions' => [], 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        ProductAccountEligibility::create(['user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id, 'classification_version' => 'scope10-mariadb',
            'classified_at' => now(), 'evidence_ref' => 'scope10-close-verification']);
    }
    $writer = app(ProjectExecutionWriter::class);
    $project = $writer->createProject($owner, $workspace, ['name' => 'S10 Concurrency', 'purpose' => 'Verify delivery', 'expected_outcome' => 'One result']);
    $writer->addMember($owner, $project->fresh(), $assignee, $workspace, ['member'], $project->fresh()->plan_version);
    foreach (['source-race', 'worker-race', 'lease-recovery'] as $title) {
        Task::create(['organization_id' => $organization->id, 'workspace_id' => $workspace->id, 'project_id' => $project->id,
            'title' => $title, 'done_condition' => 'Exactly once', 'assigned_to' => $assignee->id, 'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_NORMAL, 'sort_order' => 1, 'created_by' => $owner->id]);
    }
    echo json_encode(['status' => 'seeded'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'source') {
    $owner = User::where('email', 's10-owner@example.test')->firstOrFail();
    $action = Task::where('title', $argv[2] ?? '')->firstOrFail();
    $notification = app(NotificationSourceWriter::class)->actionAssigned($owner, $action);
    echo json_encode(['notification_id' => $notification?->id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'source-delayed') {
    $owner = User::where('email', 's10-owner@example.test')->firstOrFail();
    $action = Task::where('title', $argv[2] ?? '')->firstOrFail();
    $notification = DB::transaction(function () use ($owner, $action) {
        $created = app(NotificationSourceWriter::class)->actionAssigned($owner, $action);
        usleep(1_000_000);

        return $created;
    });
    echo json_encode(['notification_id' => $notification?->id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'deliver') {
    config(['company_notifications.delivery_enabled' => true]);
    echo json_encode(app(NotificationDeliveryProcessor::class)->run(1), JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'lease-future') {
    DB::table('notification_deliveries')->where('status', 'pending')->orderByDesc('id')->limit(1)
        ->update(['lease_token' => 'scope10-held', 'leased_until_utc' => now('UTC')->addMinutes(5)]);
    exit(0);
}

if ($mode === 'lease-expire') {
    DB::table('notification_deliveries')->where('lease_token', 'scope10-held')
        ->update(['leased_until_utc' => now('UTC')->subSecond()]);
    exit(0);
}

if ($mode === 'inspect') {
    $schema = [
        'migrations' => DB::table('migrations')->count(),
        'users' => DB::table('users')->count(),
        'organizations' => DB::table('organizations')->count(),
        'workspaces' => DB::table('workspaces')->count(),
        'projects' => DB::table('projects')->count(),
        'tasks' => DB::table('tasks')->count(),
        'notifications' => DB::table('company_notifications')->count(),
        'deliveries' => DB::table('notification_deliveries')->count(),
        'delivered' => DB::table('notification_deliveries')->where('status', 'delivered')->count(),
        'attempts' => DB::table('notification_delivery_attempts')->count(),
        'duplicate_notification_keys' => DB::table('company_notifications')->select('dedupe_key')->groupBy('dedupe_key')->havingRaw('COUNT(*) > 1')->count(),
        'duplicate_delivery_intents' => DB::table('notification_deliveries')->select('company_notification_id', 'channel')->groupBy('company_notification_id', 'channel')->havingRaw('COUNT(*) > 1')->count(),
    ];
    echo json_encode(['identity' => $identity, 'schema' => $schema], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'schema') {
    $tables = [
        'organization_notification_policies',
        'organization_notification_calendar_dates',
        'user_notification_preferences',
        'company_notifications',
        'notification_deliveries',
        'notification_delivery_attempts',
        'push_subscriptions',
    ];
    $placeholders = implode(',', array_fill(0, count($tables), '?'));
    $tableStatement = $root->prepare(
        'SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema = ? AND table_name IN ('.$placeholders.') ORDER BY table_name'
    );
    $tableStatement->execute([$database, ...$tables]);
    $indexStatement = $root->prepare(
        'SELECT table_name, index_name, non_unique, GROUP_CONCAT(column_name ORDER BY seq_in_index) columns_list '
        .'FROM information_schema.statistics WHERE table_schema = ? AND table_name IN ('.$placeholders.') '
        .'GROUP BY table_name, index_name, non_unique ORDER BY table_name, index_name'
    );
    $indexStatement->execute([$database, ...$tables]);
    $foreignKeyStatement = $root->prepare(
        'SELECT table_name, constraint_name, referenced_table_name FROM information_schema.referential_constraints '
        .'WHERE constraint_schema = ? AND table_name IN ('.$placeholders.') ORDER BY table_name, constraint_name'
    );
    $foreignKeyStatement->execute([$database, ...$tables]);
    echo json_encode([
        'tables' => $tableStatement->fetchAll(PDO::FETCH_ASSOC),
        'indexes' => $indexStatement->fetchAll(PDO::FETCH_ASSOC),
        'foreign_keys' => $foreignKeyStatement->fetchAll(PDO::FETCH_ASSOC),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

scopeTenFail('Unknown Scope 10 MariaDB verification mode.');
