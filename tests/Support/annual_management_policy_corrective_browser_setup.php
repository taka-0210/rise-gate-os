<?php

declare(strict_types=1);

use App\Models\AnnualManagementPolicy;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyPermissionManager;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyWriter;
use App\Services\AnnualManagementPolicy\ManagementPeriodWriter;
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
    || ! str_starts_with($databaseName, 'company-os-35a-corrective-browser-')
    || ! str_ends_with($databaseName, '.sqlite')) {
    fwrite(STDERR, "Refusing to prepare a database outside the guarded 35A temp path.\n");
    exit(64);
}

if (is_file($databasePath)) {
    unlink($databasePath);
}
if (! touch($databasePath)) {
    fwrite(STDERR, "Unable to create the guarded 35A temp database.\n");
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
    'name' => '35A Corrective Browser株式会社',
    'slug' => '35a-corrective-browser-'.strtolower((string) Str::ulid()),
]);
$owner = User::query()->create([
    'name' => '35A Review Owner',
    'email' => '35a-corrective-owner@example.test',
    'email_verified_at' => now(),
    'password' => Hash::make('not-used'),
    'is_active' => true,
]);
$ownerMembership = OrganizationUser::query()->create([
    'organization_id' => $organization->id,
    'user_id' => $owner->id,
    'role' => OrganizationUser::ROLE_OWNER,
    'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
    'membership_status' => OrganizationUser::STATUS_ACTIVE,
    'access_epoch' => 1,
    'lifecycle_version' => 1,
    'permissions' => [],
    'joined_at' => now(),
]);
ProductAccountEligibility::query()->create([
    'user_id' => $owner->id,
    'mode' => ProductAccountEligibility::MODE_SINGLE,
    'product_organization_id' => $organization->id,
    'classification_version' => '35a-corrective-browser-v001',
    'classified_at' => now(),
    'evidence_ref' => '35a-corrective-browser',
]);
$staff = User::query()->create([
    'name' => '35A Review Staff',
    'email' => '35a-corrective-staff@example.test',
    'email_verified_at' => now(),
    'password' => Hash::make('not-used'),
    'is_active' => true,
]);
$staffMembership = OrganizationUser::query()->create([
    'organization_id' => $organization->id,
    'user_id' => $staff->id,
    'role' => OrganizationUser::ROLE_MEMBER,
    'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
    'membership_status' => OrganizationUser::STATUS_ACTIVE,
    'access_epoch' => 1,
    'lifecycle_version' => 1,
    'permissions' => [],
    'joined_at' => now(),
]);

$sales = OrganizationGroup::query()->create([
    'organization_id' => $organization->id,
    'name' => '営業部',
]);
$support = OrganizationGroup::query()->create([
    'organization_id' => $organization->id,
    'name' => '支援部',
]);
$period = app(ManagementPeriodWriter::class)->register(
    $owner,
    $organization,
    '2026年度',
    '2026-12-01',
    '2027-11-30',
    (string) Str::uuid(),
    23,
);
$policy = app(AnnualManagementPolicyPermissionManager::class)->initialize(
    $owner,
    $organization,
    $period,
    (string) Str::uuid(),
);
app(AnnualManagementPolicyPermissionManager::class)->update(
    $owner,
    $organization,
    $policy,
    AnnualManagementPolicy::VIEW_SCOPE_EXPLICIT,
    [
        $ownerMembership->id => [
            'can_view_approved' => true,
            'can_view_draft' => true,
            'can_edit' => true,
            'can_approve' => true,
        ],
        $staffMembership->id => [
            'can_view_approved' => true,
            'can_view_draft' => false,
            'can_edit' => false,
            'can_approve' => false,
        ],
    ],
    (string) Str::uuid(),
);
$writer = app(AnnualManagementPolicyWriter::class);
$saved = $writer->saveDraft($owner, $policy, [
    'period_name' => '2026年度',
    'starts_on' => '2026-12-01',
    'ends_on' => '2027-11-30',
    'purpose' => '強みを会社の仕組みとして再現できる状態をつくる。',
    'background' => '個人の経験に依存せず、全社で同じ方向へ進む必要がある。',
    'policy' => '強みを仕組みに変え、全員が同じ方向を向いて動ける会社をつくる。',
    'themes' => [
        [
            'statement' => '顧客価値を高める',
            'explanation' => '価値で選ばれる仕事を増やす。',
            'priorities' => [
                ['statement' => '提案品質を高める', 'explanation' => '顧客理解を共通基準にする。'],
                ['statement' => '対応速度を高める', 'explanation' => '部門を越えて連携する。'],
            ],
        ],
        [
            'statement' => '組織基盤を整える',
            'explanation' => '人と仕組みの両面から成長を支える。',
            'priorities' => [
                ['statement' => '標準化を進める', 'explanation' => '改善を再利用できる形にする。'],
            ],
        ],
    ],
    'departments' => [
        [
            'group_public_id' => $sales->public_id,
            'introduction' => '顧客価値を起点に営業活動を整える。',
            'statements' => [
                ['statement' => '提案の再現性を高める', 'explanation' => '成功事例を共有する。'],
                ['statement' => '案件連携を早める', 'explanation' => '判断待ちを減らす。'],
            ],
        ],
        [
            'group_public_id' => $support->public_id,
            'introduction' => '全社の成果を仕組みで支える。',
            'statements' => [
                ['statement' => '共通基盤を整える', 'explanation' => '安全で使いやすい環境を保つ。'],
            ],
        ],
    ],
], 0, (string) Str::uuid());
$preview = $writer->preview($owner, $saved);
$revision = $writer->approve(
    $owner,
    $saved,
    1,
    null,
    0,
    $preview['snapshot_hash'],
    false,
    '35A Corrective browser fixture',
    (string) Str::uuid(),
);

echo json_encode([
    'database' => $databasePath,
    'organization_id' => $organization->id,
    'owner_id' => $owner->id,
    'annual_policy_public_id' => $saved->public_id,
    'revision_no' => $revision->revision_no,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
