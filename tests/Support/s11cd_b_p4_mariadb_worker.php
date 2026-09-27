<?php

declare(strict_types=1);

use App\Contracts\AiCommonAudioInspector;
use App\Contracts\AiCommonProvider;
use App\Contracts\AiCommonTranscriptionProvider;
use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedSessionConsent;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedCoWriter;
use App\Services\AiCommon\AiCommonSharedSessionAudioWriter;
use App\Services\AiCommon\AiCommonSharedSessionWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne('select version() as version, @@datadir as datadir');
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 's11cd_b_p4'
    || ! str_starts_with((string) $identity->version, '10.11.19-')
    || ! str_contains(str_replace('\\', '/', (string) $identity->datadir), '/company-os-s11cd-b-p4-mariadb-10.11.19-')) {
    fwrite(STDERR, 'Unsafe B-P4 concurrency database.'.PHP_EOL);
    exit(64);
}

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $owner = User::factory()->create(['email' => 's11cd-b-p4-owner@example.test']);
    $member = User::factory()->create(['email' => 's11cd-b-p4-member@example.test']);
    $organization = Organization::query()->create(['name' => 'B P4 Concurrency', 'slug' => 's11cd-b-p4-concurrency']);
    foreach ([[$owner, 'owner'], [$member, 'member']] as [$user, $role]) {
        $organization->users()->attach($user->id, ['role' => $role, 'organization_role' => $role, 'membership_status' => OrganizationUser::STATUS_ACTIVE, 'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now()]);
        ProductAccountEligibility::query()->create(['user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE, 'product_organization_id' => $organization->id, 'classification_version' => 'b-p4-concurrency', 'classified_at' => now(), 'evidence_ref' => 'b-p4-concurrency']);
    }
    OrganizationAiPolicy::query()->create(['organization_id' => $organization->id, 'is_enabled' => true, 'allows_transcription' => true, 'allowed_categories' => ['common_entry'], 'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now()]);
    $writer = app(AiCommonSharedConversationWriter::class);
    $conversation = $writer->create($owner, $organization, ['operation_id' => '41111111-1111-4111-8111-111111111111', 'name' => 'Concurrent P4 Shared', 'purpose' => 'One long context request']);
    $invitation = $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => '42222222-2222-4222-8222-222222222222']);
    $writer->accept($member, $organization, $invitation);
    $sessions = app(AiCommonSharedSessionWriter::class);
    $session = $sessions->prepare($owner, $organization, $conversation, ['operation_id' => '43333333-3333-4333-8333-333333333333', 'mode' => 'shared_room']);
    foreach ([$owner, $member] as $index => $user) {
        $sessions->decideConsent($user, $organization, $conversation, $session, [
            'operation_id' => $index === 0 ? '44444444-4444-4444-8444-444444444444' : '45555555-5555-4555-8555-555555555555',
            'consents' => array_fill_keys(AiCommonSharedSessionConsent::PURPOSES, 'granted'),
        ]);
    }
    $session = $sessions->activate($owner, $organization, $conversation, $session);
    $stream = $sessions->startStream($owner, $organization, $conversation, $session, ['operation_id' => '46666666-6666-4666-8666-666666666666', 'client_instance_id' => '47777777-7777-4777-8777-777777777777', 'mode' => 'shared_room']);
    $app->instance(AiCommonAudioInspector::class, new class implements AiCommonAudioInspector
    {
        public function inspect(string $binary, string $extension, string $mimeType): array
        {
            return ['mime_type' => 'audio/webm', 'extension' => 'webm', 'codec' => 'opus', 'duration_ms' => 1000];
        }
    });
    $app->instance(AiCommonTranscriptionProvider::class, new class implements AiCommonTranscriptionProvider
    {
        public function transcribe(string $binary, string $mimeType, string $extension): array
        {
            return ['text' => 'Concurrent bounded transcript', 'provider' => 'fake', 'model' => 'p4', 'segments' => [['speaker' => 'Speaker A', 'text' => 'Concurrent bounded transcript', 'start_ms' => 0, 'end_ms' => 1000, 'confidence' => .9]]];
        }
    });
    $audio = app(AiCommonSharedSessionAudioWriter::class);
    $window = $audio->recordWindow($owner, $organization, $conversation, $session, $stream, UploadedFile::fake()->createWithContent('p4.webm', 'p4-audio'), 1, 1, '48888888-8888-4888-8888-888888888888');
    $audio->transcribe($owner, $organization, $conversation, $session, $window, '49999999-9999-4999-8999-999999999999');
    echo json_encode(['session' => $session->public_id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

if ($mode !== 'request') {
    fwrite(STDERR, 'Unknown mode.'.PHP_EOL);
    exit(65);
}
$barrier = $argv[2] ?? '';
$temp = str_replace('\\', '/', sys_get_temp_dir());
if ($barrier === '' || ! str_starts_with(str_replace('\\', '/', $barrier), $temp.'/s11cd-b-p4-concurrency-')) {
    fwrite(STDERR, 'Unsafe barrier.'.PHP_EOL);
    exit(66);
}
for ($wait = 0; $wait < 500 && ! is_file($barrier); $wait++) {
    usleep(20_000);
}
if (! is_file($barrier)) {
    exit(67);
}

$app->instance(AiCommonProvider::class, new class implements AiCommonProvider
{
    public function respond(array $messages, array $sources): array
    {
        usleep(500_000);
        $system = collect($messages)->where('role', 'system')->pluck('content')->implode(' ');
        $answer = str_contains($system, 'Maintain bounded rolling context')
            ? '{"current_topic":["concurrency"],"main_views":[],"agreement_candidates":[],"open_questions":[],"to_confirm":[],"source_refs":[]}'
            : 'One shared P4 answer';

        return ['answer' => $answer, 'citations' => [], 'provider' => 'fake', 'model' => 'p4-concurrency', 'input_tokens' => null, 'output_tokens' => null];
    }
});
$owner = User::query()->where('email', 's11cd-b-p4-owner@example.test')->firstOrFail();
$organization = Organization::query()->where('slug', 's11cd-b-p4-concurrency')->firstOrFail();
$conversation = AiCommonConversation::query()->where('title', 'Concurrent P4 Shared')->firstOrFail();
$request = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
    'operation_id' => '4aaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'content' => 'Run one shared long context request',
    'source_ids' => [],
]);
echo json_encode(['request' => $request->id, 'state' => $request->state], JSON_THROW_ON_ERROR).PHP_EOL;
