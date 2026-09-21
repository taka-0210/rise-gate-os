<?php

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$databasePath = $argv[1] ?? '';
$resolvedTemp = realpath(sys_get_temp_dir());
$databaseDirectory = realpath(dirname($databasePath));
$databaseName = basename($databasePath);

if ($resolvedTemp === false
    || $databaseDirectory !== $resolvedTemp
    || ! str_starts_with($databaseName, 'company-os-pux-a-browser-')
    || ! str_ends_with($databaseName, '.sqlite')) {
    fwrite(STDERR, "Refusing to prepare a database outside the guarded PUX-A temp path.\n");
    exit(1);
}

if (! is_file($databasePath) && ! touch($databasePath)) {
    fwrite(STDERR, "Unable to create the guarded PUX-A temp database.\n");
    exit(1);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'app.env' => 'testing',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $databasePath,
    'product_ux.organization_admission_enabled' => true,
]);
DB::purge('sqlite');
Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

$createUser = static fn (string $name, string $email): User => User::query()->create([
    'name' => $name,
    'email' => $email,
    'email_verified_at' => now(),
    'password' => Hash::make('not-used'),
    'is_active' => true,
]);

$createOrganization = static function (User $user, string $name, string $slug, string $status = OrganizationUser::STATUS_ACTIVE): OrganizationUser {
    $organization = Organization::query()->create([
        'name' => $name,
        'slug' => $slug,
    ]);
    $membership = OrganizationUser::query()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => OrganizationUser::ROLE_OWNER,
        'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
        'membership_status' => $status,
        'company_role' => OrganizationUser::COMPANY_ROLE_OWNER,
        'permissions' => OrganizationUser::adminPermissions(),
        'joined_at' => now(),
    ]);

    if ($status === OrganizationUser::STATUS_ACTIVE) {
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => $name.' Standard Workspace',
            'slug' => 'standard',
            'billing_type' => Workspace::BILLING_INCLUDED,
            'status' => Workspace::STATUS_ACTIVE,
            'type' => Workspace::TYPE_SHARED,
        ]);
        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);
        $organization->update(['standard_workspace_id' => $workspace->id]);
    }

    return $membership;
};

$single = $createUser('PUX Single User', 'pux-single@example.test');
$singleMembership = $createOrganization($single, 'PUX Single Company', 'pux-single-company');
ProductAccountEligibility::query()->create([
    'user_id' => $single->id,
    'mode' => ProductAccountEligibility::MODE_SINGLE,
    'product_organization_id' => $singleMembership->organization_id,
    'classification_version' => 'pux-a-browser',
    'classified_at' => now(),
    'evidence_ref' => 'browser:single',
]);

$review = $createUser('PUX Review User', 'pux-review@example.test');
$createOrganization($review, 'PUX Active Alpha', 'pux-active-alpha');
$createOrganization($review, 'PUX Active Beta', 'pux-active-beta');
$createOrganization($review, 'PUX Suspended Hidden', 'pux-suspended-hidden', OrganizationUser::STATUS_SUSPENDED);
ProductAccountEligibility::query()->create([
    'user_id' => $review->id,
    'mode' => ProductAccountEligibility::MODE_REVIEW_REQUIRED,
    'product_organization_id' => null,
    'classification_version' => 'pux-a-browser',
    'classified_at' => now(),
    'evidence_ref' => 'browser:review',
]);

$unstarted = $createUser('PUX Unstarted User', 'pux-unstarted@example.test');
ProductAccountEligibility::query()->create([
    'user_id' => $unstarted->id,
    'mode' => ProductAccountEligibility::MODE_UNSTARTED,
    'product_organization_id' => null,
    'classification_version' => 'pux-a-browser',
    'classified_at' => now(),
    'evidence_ref' => 'browser:unstarted',
]);

echo json_encode([
    'database' => $databasePath,
    'users' => User::query()->count(),
    'organizations' => Organization::query()->count(),
    'eligibilities' => ProductAccountEligibility::query()->count(),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
