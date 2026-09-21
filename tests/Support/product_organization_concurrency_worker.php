<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$databasePath = $argv[2] ?? '';
$temporaryDirectory = realpath(sys_get_temp_dir());
$databaseDirectory = $databasePath === '' ? false : realpath(dirname($databasePath));
if ($temporaryDirectory === false || $databaseDirectory !== $temporaryDirectory
    || ! str_starts_with(basename($databasePath), 'company-os-pux-a-concurrency-')) {
    fwrite(STDERR, "Refusing non-PUX-A temporary fixture.\n");
    exit(64);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'product_ux.organization_admission_enabled' => true,
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $databasePath,
    'database.connections.sqlite.busy_timeout' => 100,
    'database.connections.sqlite.transaction_mode' => 'DEFERRED',
]);
DB::purge('sqlite');

if ($mode === 'seed') {
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    touch($databasePath);
    Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);
    $user = User::query()->create([
        'name' => 'PUX Race',
        'email' => 'pux-race@example.test',
        'password' => Hash::make('not-used'),
        'is_active' => true,
    ]);
    $first = Organization::query()->create(['name' => 'Race A', 'slug' => 'race-a']);
    $second = Organization::query()->create(['name' => 'Race B', 'slug' => 'race-b']);
    app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'concurrency-seed');
    echo json_encode(['user_id' => $user->id, 'organization_ids' => [$first->id, $second->id]], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($mode === 'inspect') {
    $userId = (int) ($argv[3] ?? 0);
    $eligibility = ProductAccountEligibility::query()->where('user_id', $userId)->firstOrFail();
    echo json_encode([
        'mode' => $eligibility->mode,
        'product_organization_id' => $eligibility->product_organization_id,
        'membership_count' => OrganizationUser::query()->where('user_id', $userId)->count(),
        'organization_count' => Organization::query()->count(),
        'binding_audit_count' => DB::table('account_security_events')
            ->where('user_id', $userId)->where('event', 'account.product_organization.bound')->count(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

$operation = $mode;
$userId = (int) ($argv[3] ?? 0);
$organizationId = (int) ($argv[4] ?? 0);
$entryCode = (string) ($argv[5] ?? 'unknown');
$readyPath = (string) ($argv[6] ?? '');
$goPath = (string) ($argv[7] ?? '');
$worker = (string) ($argv[8] ?? 'worker');
$user = User::query()->findOrFail($userId);
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
    if ($operation === 'admit-existing') {
        $organization = Organization::query()->findOrFail($organizationId);
        $result = app(ProductOrganizationAdmission::class)->admitExistingOrganization(
            $user,
            $organization,
            $entryCode,
            function () use ($user, $organization, $worker): string {
                OrganizationUser::query()->firstOrCreate(
                    ['organization_id' => $organization->id, 'user_id' => $user->id],
                    [
                        'role' => OrganizationUser::ROLE_MEMBER,
                        'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
                        'membership_status' => OrganizationUser::STATUS_ACTIVE,
                        'joined_at' => now(),
                    ],
                );
                usleep(100_000);

                return $worker;
            },
        );
    } elseif ($operation === 'admit-new') {
        $result = app(ProductOrganizationAdmission::class)->admitNewOrganization(
            $user,
            $entryCode,
            function () use ($user, $worker): array {
                $organization = Organization::query()->create([
                    'name' => 'New '.$worker,
                    'slug' => 'new-'.strtolower($worker).'-'.bin2hex(random_bytes(4)),
                ]);
                OrganizationUser::query()->create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'role' => OrganizationUser::ROLE_MEMBER,
                    'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
                    'membership_status' => OrganizationUser::STATUS_ACTIVE,
                    'joined_at' => now(),
                ]);
                usleep(100_000);

                return ['result' => $worker, 'organization' => $organization];
            },
        );
    } else {
        throw new RuntimeException('Unknown operation.');
    }
    echo json_encode(['worker' => $worker, 'status' => 'success', 'result' => $result], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'worker' => $worker,
        'status' => 'rejected',
        'exception' => $exception::class,
    ], JSON_THROW_ON_ERROR);
    exit(2);
}
