<?php

declare(strict_types=1);

use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedSession;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedSessionWriter;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne('select version() as version, @@datadir as datadir');
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 's11cd_b_p3'
    || ! str_starts_with((string) $identity->version, '10.11.19-')
    || ! str_contains(str_replace('\\', '/', (string) $identity->datadir), '/company-os-s11cd-b-p3-mariadb-10.11.19-')) {
    fwrite(STDERR, 'Unsafe B-P3 concurrency database.'.PHP_EOL);
    exit(64);
}

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $owner = User::factory()->create(['email' => 's11cd-b-p3-owner@example.test']);
    $member = User::factory()->create(['email' => 's11cd-b-p3-member@example.test']);
    $organization = Organization::query()->create(['name' => 'B P3 Concurrency', 'slug' => 's11cd-b-p3-concurrency']);
    foreach ([[$owner, 'owner'], [$member, 'member']] as [$user, $role]) {
        $organization->users()->attach($user->id, ['role' => $role, 'organization_role' => $role, 'membership_status' => OrganizationUser::STATUS_ACTIVE, 'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now()]);
        ProductAccountEligibility::query()->create(['user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE, 'product_organization_id' => $organization->id, 'classification_version' => 'b-p3-concurrency', 'classified_at' => now(), 'evidence_ref' => 'b-p3-concurrency']);
    }
    OrganizationAiPolicy::query()->create(['organization_id' => $organization->id, 'is_enabled' => true, 'allows_transcription' => true, 'allowed_categories' => ['common_entry'], 'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now()]);
    $writer = app(AiCommonSharedConversationWriter::class);
    $conversation = $writer->create($owner, $organization, ['operation_id' => '31111111-1111-4111-8111-111111111111', 'name' => 'Concurrent P3 Shared', 'purpose' => 'One capture stream']);
    $invitation = $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => '32222222-2222-4222-8222-222222222222']);
    $writer->accept($member, $organization, $invitation);
    $sessions = app(AiCommonSharedSessionWriter::class);
    $session = $sessions->prepare($owner, $organization, $conversation, ['operation_id' => '33333333-3333-4333-8333-333333333333', 'mode' => 'shared_room']);
    foreach ([$owner, $member] as $user) {
        $sessions->decideConsent($user, $organization, $conversation, $session, [
            'operation_id' => $user->id === $owner->id ? '34444444-4444-4444-8444-444444444444' : '35555555-5555-4555-8555-555555555555',
            'consents' => array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, AiCommonSharedSessionConsent::STATUS_GRANTED),
        ]);
    }
    $sessions->activate($owner, $organization, $conversation, $session);
    echo json_encode(['session' => $session->public_id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

if ($mode !== 'stream') {
    fwrite(STDERR, 'Unknown mode.'.PHP_EOL);
    exit(65);
}
$barrier = $argv[2] ?? '';
$temp = str_replace('\\', '/', sys_get_temp_dir());
if ($barrier === '' || ! str_starts_with(str_replace('\\', '/', $barrier), $temp.'/s11cd-b-p3-concurrency-')) {
    fwrite(STDERR, 'Unsafe barrier.'.PHP_EOL);
    exit(66);
}
for ($wait = 0; $wait < 500 && ! is_file($barrier); $wait++) {
    usleep(20_000);
}
if (! is_file($barrier)) {
    exit(67);
}

$owner = User::query()->where('email', 's11cd-b-p3-owner@example.test')->firstOrFail();
$organization = Organization::query()->where('slug', 's11cd-b-p3-concurrency')->firstOrFail();
$conversation = AiCommonConversation::query()->where('title', 'Concurrent P3 Shared')->firstOrFail();
$session = AiCommonSharedSession::query()->where('ai_common_shared_conversation_id', $conversation->sharedConversation->id)->sole();
$stream = app(AiCommonSharedSessionWriter::class)->startStream($owner, $organization, $conversation, $session, [
    'operation_id' => '36666666-6666-4666-8666-666666666666',
    'client_instance_id' => '37777777-7777-4777-8777-777777777777',
    'mode' => 'shared_room',
]);
echo json_encode(['stream' => $stream->id, 'generation' => $stream->generation], JSON_THROW_ON_ERROR).PHP_EOL;
