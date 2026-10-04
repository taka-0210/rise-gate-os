<?php

declare(strict_types=1);

use App\Models\AnnualManagementPolicy;
use App\Models\ManagementDesignAccessSetting;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyPermissionManager;
use App\Services\AnnualManagementPolicy\AnnualManagementPolicyWriter;
use App\Services\AnnualManagementPolicy\ManagementPeriodWriter;
use App\Services\ManagementDesign\ManagementDesignPermissionManager;
use App\Services\ManagementDesign\ManagementDesignWriter;
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
    || ! str_starts_with($databaseName, 'company-os-context-reader-browser-')
    || ! str_ends_with($databaseName, '.sqlite')) {
    fwrite(STDERR, "Refusing to prepare a database outside the guarded Reader temp path.\n");
    exit(64);
}
if (is_file($databasePath)) {
    unlink($databasePath);
}
if (! touch($databasePath)) {
    fwrite(STDERR, "Unable to create the guarded Reader temp database.\n");
    exit(1);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'app.env' => 'testing',
    'app.timezone' => 'Asia/Tokyo',
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $databasePath,
    'product_ux.organization_admission_enabled' => false,
]);
DB::purge('sqlite');
Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

$organization = Organization::query()->create([
    'name' => 'Reader Browser株式会社',
    'slug' => 'reader-browser-'.strtolower((string) Str::ulid()),
]);
$owner = User::query()->create([
    'name' => 'Reader Browser Owner',
    'email' => 'context-reader-owner@example.test',
    'email_verified_at' => now(),
    'password' => Hash::make('not-used'),
    'is_active' => true,
]);
$membership = OrganizationUser::query()->create([
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
    'classification_version' => 'company-context-reader-browser-v001',
    'classified_at' => now(),
    'evidence_ref' => 'company-context-reader-browser',
]);

$permissionManager = app(ManagementDesignPermissionManager::class);
$writer = app(ManagementDesignWriter::class);
$content = [
    'philosophy' => [
        'statement' => '誠実な仕事で、人と地域の明日を明るくする。',
        'statement_explanation' => str_repeat('目の前の判断を、人への誠実さと長期の信頼へつなげます。', 7),
        'sections' => [
            ['title' => '存在理由', 'body' => '働く人とお客様の可能性をひらく。', 'explanation' => '日々の小さな約束を守り、信頼を積み重ねます。'],
            ['title' => '大切にする姿勢', 'body' => '短期の便利さより、続いていく価値を選ぶ。'],
            ['title' => 'ともに良くなる', 'body' => '仲間、地域、お客様を置き去りにしない。'],
        ],
    ],
    'vision' => [
        'statement' => '技術と対話がめぐり、誰もが良い仕事を続けられる社会へ。',
        'statement_explanation' => str_repeat('必要な知識と支えが、必要な場所へ届く未来を描きます。', 6),
        'horizon' => '2032｜地域の現場から広がる',
        'sections' => [
            ['title' => '現場の知恵が見える', 'body' => '経験を次の判断に使える言葉として共有する。', 'horizon' => '2028'],
            ['title' => '選ぶ理由が伝わる', 'body' => '価値と仕事への姿勢がお客様へ届く。', 'horizon' => '2029'],
            ['title' => '人と仕組みが育ち合う', 'body' => '改善が日常になる。', 'horizon' => '2030'],
        ],
    ],
    'policy' => [
        'statement' => '強みを仕組みに変え、全員が同じ方向を向いて動ける会社をつくる。',
        'statement_explanation' => str_repeat('個人の経験を共通基準へ変え、部署を越えて支え合います。', 7),
        'sections' => [
            ['title' => '判断と行動をそろえる', 'body' => '会社としての判断軸を共有する。'],
            ['title' => '強みを仕組みに変える', 'body' => '経験と技術を再現できる形にする。'],
            ['title' => '全体最適で動く', 'body' => '価値が届くまでの流れを見る。'],
            ['title' => '生産性を成果につなげる', 'body' => '同じ時間からより大きな価値を生む。'],
        ],
    ],
];
foreach ($content as $type => $payload) {
    $permissionManager->update(
        $owner, $organization, $type, ManagementDesignAccessSetting::VIEW_SCOPE_EXPLICIT,
        [$membership->id => ['can_view' => true, 'can_edit' => true]],
        (string) Str::uuid(),
    );
    $writer->saveOfficial($owner, $organization, $type, $payload, 0, null, (string) Str::uuid());
}

$period = app(ManagementPeriodWriter::class)->register(
    $owner, $organization, '2026年度', '2026-01-01', '2026-12-31', (string) Str::uuid(), 23,
);
$annual = app(AnnualManagementPolicyPermissionManager::class)->initialize(
    $owner, $organization, $period, (string) Str::uuid(),
);
app(AnnualManagementPolicyPermissionManager::class)->update(
    $owner, $organization, $annual, AnnualManagementPolicy::VIEW_SCOPE_EXPLICIT,
    [$membership->id => ['can_view_approved' => true, 'can_view_draft' => true, 'can_edit' => true, 'can_approve' => true]],
    (string) Str::uuid(),
);
$groupA = OrganizationGroup::query()->create(['organization_id' => $organization->id, 'name' => '営業・提案']);
$groupB = OrganizationGroup::query()->create(['organization_id' => $organization->id, 'name' => 'オペレーション']);
$annualWriter = app(AnnualManagementPolicyWriter::class);
$saved = $annualWriter->saveDraft($owner, $annual, [
    'period_name' => '2026年度',
    'starts_on' => '2026-01-01',
    'ends_on' => '2026-12-31',
    'purpose' => '良い仕事が、人に依存せず続いていく会社へ。',
    'background' => str_repeat('成長によって増えた複雑さを、現場の知恵が循環する次の強さへ変えます。', 5),
    'policy' => '現場の知恵をつなぎ、価値が届く速さと確かさを高める。',
    'themes' => [
        ['statement' => 'お客様の時間を守る', 'explanation' => '相談から提供までの停滞を減らします。', 'priorities' => [
            ['statement' => '最初の相談で次の一歩を明確にする', 'explanation' => '担当が変わってもお客様を迷わせません。'],
            ['statement' => '説明の品質をそろえる', 'explanation' => '選択肢と違いを人の言葉で伝えます。'],
        ]],
        ['statement' => '現場の知恵を会社の力にする', 'explanation' => '良い実践を言葉と仕組みに変えます。', 'priorities' => [
            ['statement' => '良い実践をその日のうちに残す', 'explanation' => '事実と気づきを小さく記録します。'],
            ['statement' => '標準を改善できる形にする', 'explanation' => 'より良い方法へ更新し続けます。'],
        ]],
    ],
    'departments' => [
        ['group_public_id' => $groupA->public_id, 'introduction' => '相談の背景まで理解する。', 'statements' => [
            ['statement' => '選べる提案を届ける', 'explanation' => 'お客様が実現したい状態を正確につなぎます。'],
        ]],
        ['group_public_id' => $groupB->public_id, 'introduction' => '流れの詰まりを見つける。', 'statements' => [
            ['statement' => '確かな提供へ変える', 'explanation' => '問題が大きくなる前に共有します。'],
        ]],
    ],
], 0, (string) Str::uuid());
$preview = $annualWriter->preview($owner, $saved);
$annualWriter->approve($owner, $saved, 1, null, 0, $preview['snapshot_hash'], false, null, (string) Str::uuid());

echo json_encode([
    'database' => $databasePath,
    'organization_id' => $organization->id,
    'owner_id' => $owner->id,
    'annual_public_id' => $annual->public_id,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL;
