<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 's11cd_b_p1') {
    fwrite(STDERR, "Unsafe B-P1 concurrency database.\n");
    exit(64);
}

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $owner = User::factory()->create(['email' => 's11cd-b-p1-owner@example.test']);
    $organization = Organization::query()->create([
        'name' => 'S11CD B P1 Concurrency',
        'slug' => 's11cd-b-p1-concurrency',
    ]);
    $organization->users()->attach($owner->id, [
        'role' => 'owner',
        'organization_role' => 'owner',
        'membership_status' => OrganizationUser::STATUS_ACTIVE,
        'access_epoch' => 1,
        'lifecycle_version' => 1,
        'joined_at' => now(),
    ]);
    ProductAccountEligibility::query()->create([
        'user_id' => $owner->id,
        'mode' => ProductAccountEligibility::MODE_SINGLE,
        'product_organization_id' => $organization->id,
        'classification_version' => 's11cd-b-p1-concurrency',
        'classified_at' => now(),
        'evidence_ref' => 's11cd-b-p1-concurrency',
    ]);
    echo json_encode(['owner' => $owner->id, 'organization' => $organization->id], JSON_THROW_ON_ERROR);
    exit;
}

if ($mode !== 'create') {
    fwrite(STDERR, "Unknown mode.\n");
    exit(65);
}

$barrier = $argv[2] ?? '';
$temp = str_replace('\\', '/', sys_get_temp_dir());
if ($barrier === '' || ! str_starts_with(str_replace('\\', '/', $barrier), $temp.'/s11cd-b-p1-concurrency-')) {
    fwrite(STDERR, "Unsafe barrier.\n");
    exit(66);
}
for ($wait = 0; $wait < 500 && ! is_file($barrier); $wait++) {
    usleep(20_000);
}
if (! is_file($barrier)) {
    fwrite(STDERR, "Barrier timeout.\n");
    exit(67);
}

$owner = User::query()->where('email', 's11cd-b-p1-owner@example.test')->firstOrFail();
$organization = Organization::query()->where('slug', 's11cd-b-p1-concurrency')->firstOrFail();
$conversation = app(AiCommonSharedConversationWriter::class)->create($owner, $organization, [
    'operation_id' => '11111111-1111-4111-8111-111111111111',
    'name' => 'Concurrent Shared',
    'purpose' => 'Converge the same operation',
]);

echo json_encode(['conversation' => $conversation->id], JSON_THROW_ON_ERROR);
