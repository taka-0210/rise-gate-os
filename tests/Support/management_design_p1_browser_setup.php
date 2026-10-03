<?php

declare(strict_types=1);

use App\Models\ManagementDesignAccessSetting;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\ManagementDesign\ManagementDesignPermissionManager;
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
    || ! str_starts_with($databaseName, 'company-os-mdc-p1-browser-')
    || ! str_ends_with($databaseName, '.sqlite')) {
    fwrite(STDERR, "Refusing to prepare a database outside the guarded MDC-P1 temp path.\n");
    exit(64);
}

if (is_file($databasePath)) {
    unlink($databasePath);
}
if (! touch($databasePath)) {
    fwrite(STDERR, "Unable to create the guarded MDC-P1 temp database.\n");
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
    'name' => 'MDC P1 Browser株式会社',
    'slug' => 'mdc-p1-browser-'.strtolower((string) Str::ulid()),
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
    ProductAccountEligibility::query()->create([
        'user_id' => $user->id,
        'mode' => ProductAccountEligibility::MODE_SINGLE,
        'product_organization_id' => $organization->id,
        'classification_version' => 'mdc-p1-browser-v001',
        'classified_at' => now(),
        'evidence_ref' => 'mdc-p1-browser',
    ]);

    return [$user, $membership];
};

[$owner, $ownerMembership] = $createMember(
    'MDC P1 Owner',
    'mdc-p1-owner@example.test',
    OrganizationUser::ORGANIZATION_ROLE_OWNER,
);
[, $staffMembership] = $createMember(
    'MDC P1 Staff',
    'mdc-p1-staff@example.test',
    OrganizationUser::ORGANIZATION_ROLE_MEMBER,
);

$permissionManager = app(ManagementDesignPermissionManager::class);
foreach (['philosophy', 'vision', 'policy'] as $type) {
    $permissionManager->update(
        $owner,
        $organization,
        $type,
        ManagementDesignAccessSetting::VIEW_SCOPE_EXPLICIT,
        [
            $ownerMembership->id => ['can_view' => true, 'can_edit' => true],
            $staffMembership->id => ['can_view' => true, 'can_edit' => false],
        ],
        (string) Str::uuid(),
    );
}

echo json_encode([
    'database' => $databasePath,
    'organization_id' => $organization->id,
    'owner_id' => $owner->id,
    'owner_membership_id' => $ownerMembership->id,
    'staff_membership_id' => $staffMembership->id,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
