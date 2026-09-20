<?php

declare(strict_types=1);

use App\Models\BusinessDomain;
use App\Models\BusinessDomainEditorGrant;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\BusinessDomain\BusinessDomainGrantManager;
use App\Services\BusinessDomain\BusinessDomainWriter;
use App\Services\Organization\OrganizationAdministration;
use App\Services\Organization\OrganizationMembershipLifecycle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$databasePath = $argv[2] ?? '';
$temporaryDirectory = realpath(sys_get_temp_dir());
$databaseDirectory = $databasePath === '' ? false : realpath(dirname($databasePath));
if ($temporaryDirectory === false
    || $databaseDirectory !== $temporaryDirectory
    || ! str_starts_with(basename($databasePath), 'company-os-scope7-concurrency-')) {
    fwrite(STDERR, "Refusing to use a database outside the Scope 7 temporary fixture.\n");
    exit(64);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $databasePath,
    'database.connections.sqlite.busy_timeout' => 10000,
]);
DB::purge('sqlite');

if ($mode === 'seed') {
    $scenario = $argv[3] ?? '';
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    touch($databasePath);
    Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);
    DB::statement('PRAGMA journal_mode = WAL');
    DB::statement('PRAGMA busy_timeout = 10000');
    $organization = Organization::query()->create([
        'name' => 'Scope 7 Concurrency',
        'slug' => 'scope7-concurrency-'.strtolower((string) Str::ulid()),
    ]);
    [$owner, $ownerMembership] = createMember($organization, 'owner', 'scope7-owner@example.test');
    $targetRole = $scenario === 'role' ? 'owner' : 'admin';
    [$target, $targetMembership] = createMember($organization, $targetRole, 'scope7-target@example.test');
    if ($targetRole !== 'owner') {
        app(BusinessDomainGrantManager::class)->grant(
            $owner,
            $organization,
            $targetMembership,
            'seed-grant-'.$scenario,
        );
    }
    $domain = app(BusinessDomainWriter::class)->create(
        $target,
        $organization,
        ['name' => 'Concurrent Domain', 'items' => []],
        'seed-create-'.$scenario,
    );
    echo json_encode([
        'organization_id' => $organization->id,
        'owner_id' => $owner->id,
        'target_id' => $target->id,
        'target_membership_id' => $targetMembership->id,
        'domain_id' => $domain->id,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'inspect') {
    $organizationId = (int) ($argv[3] ?? 0);
    $targetMembershipId = (int) ($argv[4] ?? 0);
    $domainId = (int) ($argv[5] ?? 0);
    $domain = BusinessDomain::query()->findOrFail($domainId);
    $membership = OrganizationUser::query()->findOrFail($targetMembershipId);
    $grant = BusinessDomainEditorGrant::query()
        ->where('organization_id', $organizationId)
        ->where('organization_user_id', $targetMembershipId)
        ->first();
    echo json_encode([
        'domain_version' => $domain->version,
        'domain_name' => $domain->name,
        'membership_status' => $membership->membership_status,
        'organization_role' => $membership->organization_role,
        'grant_active' => $grant !== null && $grant->revoked_at === null,
        'revision_count' => $domain->revisions()->count(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if (! in_array($mode, ['update', 'suspend', 'revoke', 'demote'], true)) {
    fwrite(STDERR, "Unknown mode.\n");
    exit(64);
}

$organizationId = (int) ($argv[3] ?? 0);
$ownerId = (int) ($argv[4] ?? 0);
$targetId = (int) ($argv[5] ?? 0);
$targetMembershipId = (int) ($argv[6] ?? 0);
$domainId = (int) ($argv[7] ?? 0);
$readyPath = $argv[8] ?? '';
$goPath = $argv[9] ?? '';
$worker = $argv[10] ?? $mode;

touch($readyPath);
$deadline = microtime(true) + 15;
while (! is_file($goPath) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($goPath)) {
    fwrite(STDERR, "Barrier timeout.\n");
    exit(70);
}

try {
    DB::statement('PRAGMA busy_timeout = 10000');
    $organization = Organization::query()->findOrFail($organizationId);
    $owner = User::query()->findOrFail($ownerId);
    $target = User::query()->findOrFail($targetId);
    $membership = OrganizationUser::query()->findOrFail($targetMembershipId);
    $domain = BusinessDomain::query()->findOrFail($domainId);
    match ($mode) {
        'update' => app(BusinessDomainWriter::class)->update(
            $target,
            $organization,
            $domain,
            ['name' => 'Updated by '.$worker, 'items' => []],
            1,
            'real connection race',
            'race-update-'.$worker,
        ),
        'suspend' => app(OrganizationMembershipLifecycle::class)->execute(
            $owner,
            $organization,
            $membership,
            OrganizationMembershipLifecycle::COMMAND_SUSPEND,
            'real connection race',
            1,
            'race-suspend-'.$worker,
        ),
        'revoke' => app(BusinessDomainGrantManager::class)->revoke(
            $owner,
            $organization,
            $membership,
            'race-revoke-'.$worker,
        ),
        'demote' => app(OrganizationAdministration::class)->updateRole(
            $owner,
            $organization,
            $membership,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
        ),
    };
    echo json_encode(['worker' => $worker, 'status' => 'success'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'worker' => $worker,
        'status' => 'failed',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(2);
}

function createMember(Organization $organization, string $role, string $email): array
{
    $user = User::query()->create([
        'name' => ucfirst($role),
        'email' => $email,
        'password' => Hash::make('not-used'),
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $membership = OrganizationUser::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role === 'owner' ? OrganizationUser::ROLE_OWNER : OrganizationUser::ROLE_MEMBER,
        'organization_role' => $role,
        'membership_status' => OrganizationUser::STATUS_ACTIVE,
        'access_epoch' => 1,
        'lifecycle_version' => 1,
        'permissions' => [],
        'joined_at' => now(),
    ]);

    return [$user, $membership];
}
