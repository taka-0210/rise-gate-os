<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Services\BusinessDomain\BusinessDomainGrantManager;
use App\Services\BusinessDomain\BusinessDomainWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$temporaryDirectory = realpath(sys_get_temp_dir());
$databaseDirectory = $databasePath === '' ? false : realpath(dirname($databasePath));
$databaseName = basename($databasePath);

if ($temporaryDirectory === false
    || $databaseDirectory !== $temporaryDirectory
    || ! str_starts_with($databaseName, 'company-os-pux-b-browser-')
    || ! str_ends_with($databaseName, '.sqlite')) {
    fwrite(STDERR, "Refusing to prepare a database outside the guarded PUX-B temp path.\n");
    exit(64);
}

if (is_file($databasePath)) {
    unlink($databasePath);
}
if (! touch($databasePath)) {
    fwrite(STDERR, "Unable to create the guarded PUX-B temp database.\n");
    exit(1);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'testing',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $databasePath,
    'product_ux.organization_admission_enabled' => false,
]);
DB::purge('sqlite');
Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

$organization = Organization::query()->create([
    'name' => 'Company Context Browser株式会社',
    'slug' => 'pux-b-browser-'.strtolower((string) Str::ulid()),
]);

$createMember = static function (string $name, string $email, string $role) use ($organization): array {
    $user = User::query()->create([
        'name' => $name,
        'email' => $email,
        'email_verified_at' => now(),
        'password' => Hash::make('not-used'),
        'is_active' => true,
    ]);
    $membership = OrganizationUser::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role === OrganizationUser::ORGANIZATION_ROLE_OWNER
            ? OrganizationUser::ROLE_OWNER
            : OrganizationUser::ROLE_MEMBER,
        'organization_role' => $role,
        'membership_status' => OrganizationUser::STATUS_ACTIVE,
        'access_epoch' => 1,
        'lifecycle_version' => 1,
        'permissions' => [],
        'joined_at' => now(),
    ]);

    return [$user, $membership];
};

[$owner] = $createMember('PUX-B Owner', 'pux-b-owner@example.test', OrganizationUser::ORGANIZATION_ROLE_OWNER);
[$admin, $adminMembership] = $createMember('PUX-B Admin', 'pux-b-admin@example.test', OrganizationUser::ORGANIZATION_ROLE_ADMIN);
app(BusinessDomainGrantManager::class)->grant(
    $owner,
    $organization,
    $adminMembership,
    (string) Str::uuid(),
);
$domain = app(BusinessDomainWriter::class)->create(
    $owner,
    $organization,
    ['name' => '地域共創事業', 'items' => []],
    (string) Str::uuid(),
);

echo json_encode([
    'database' => $databasePath,
    'organization_id' => $organization->id,
    'owner_id' => $owner->id,
    'admin_id' => $admin->id,
    'domain_public_id' => $domain->public_id,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
