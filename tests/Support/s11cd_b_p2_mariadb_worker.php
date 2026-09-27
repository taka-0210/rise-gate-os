<?php

declare(strict_types=1);

use App\Contracts\AiCommonProvider;
use App\Models\AiCommonConversation;
use App\Models\AiResourcePolicy;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiCommon\AiCommonSharedContext;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedCoWriter;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$identity = DB::selectOne('select version() as version, @@datadir as datadir');
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || config('database.connections.mysql.database') !== 's11cd_b_p2'
    || ! str_starts_with((string) $identity->version, '10.11.19-')
    || ! str_contains(str_replace('\\', '/', (string) $identity->datadir), '/company-os-s11cd-b-p2-mariadb-10.11.19-')) {
    fwrite(STDERR, "Unsafe B-P2 concurrency database.\n");
    exit(64);
}

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $owner = User::factory()->create(['email' => 's11cd-b-p2-owner@example.test']);
    $member = User::factory()->create(['email' => 's11cd-b-p2-member@example.test']);
    $organization = Organization::query()->create(['name' => 'B P2 Concurrency', 'slug' => 's11cd-b-p2-concurrency']);
    foreach ([[$owner, 'owner'], [$member, 'member']] as [$user, $role]) {
        $organization->users()->attach($user->id, ['role' => $role, 'organization_role' => $role, 'membership_status' => OrganizationUser::STATUS_ACTIVE, 'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now()]);
        ProductAccountEligibility::query()->create(['user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE, 'product_organization_id' => $organization->id, 'classification_version' => 'b-p2-concurrency', 'classified_at' => now(), 'evidence_ref' => 'b-p2-concurrency']);
    }
    OrganizationAiPolicy::query()->create(['organization_id' => $organization->id, 'is_enabled' => true, 'allowed_categories' => ['common_entry', 'project'], 'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now()]);
    $workspace = Workspace::query()->create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id, 'name' => 'B P2', 'slug' => 'b-p2-concurrency', 'status' => Workspace::STATUS_ACTIVE]);
    $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
    $workspace->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
    WorkspaceAiSetting::query()->create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai', 'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES, 'terms_version' => WorkspaceAiSetting::TERMS_VERSION, 'enabled_by' => $owner->id, 'enabled_at' => now()]);
    $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, ['name' => 'Concurrent Project', 'purpose' => 'P2', 'expected_outcome' => 'One request']);
    app(ProjectExecutionWriter::class)->addMember($owner, $project->fresh(), $member, $workspace, ['member'], $project->fresh()->plan_version);
    AiResourcePolicy::query()->create(['organization_id' => $organization->id, 'resource_type' => 'project', 'resource_public_id' => $project->public_id, 'allows_ai_reference' => true, 'version' => 1, 'managed_by_user_id' => $owner->id]);
    $writer = app(AiCommonSharedConversationWriter::class);
    $conversation = $writer->create($owner, $organization, ['operation_id' => (string) Str::uuid(), 'name' => 'Concurrent Shared', 'purpose' => 'One authoritative request']);
    $invitation = $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
    $writer->accept($member, $organization, $invitation);
    $source = app(AiCommonSharedContext::class)->select($owner, $organization, $conversation, 'project', $project->public_id, 'Concurrent context');
    echo json_encode(['conversation' => $conversation->id, 'source' => $source->id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}

if ($mode !== 'request') {
    fwrite(STDERR, "Unknown mode.\n");
    exit(65);
}
$barrier = $argv[2] ?? '';
$temp = str_replace('\\', '/', sys_get_temp_dir());
if ($barrier === '' || ! str_starts_with(str_replace('\\', '/', $barrier), $temp.'/s11cd-b-p2-concurrency-')) {
    fwrite(STDERR, "Unsafe barrier.\n");
    exit(66);
}
for ($wait = 0; $wait < 500 && ! is_file($barrier); $wait++) {
    usleep(20_000);
}
if (! is_file($barrier)) {
    exit(67);
}

$provider = new class implements AiCommonProvider
{
    public function respond(array $messages, array $sources): array
    {
        usleep(500_000);

        return ['answer' => 'One shared answer', 'citations' => [], 'provider' => 'fake', 'model' => 'concurrency', 'input_tokens' => null, 'output_tokens' => null];
    }
};
$app->instance(AiCommonProvider::class, $provider);
$owner = User::query()->where('email', 's11cd-b-p2-owner@example.test')->firstOrFail();
$organization = Organization::query()->where('slug', 's11cd-b-p2-concurrency')->firstOrFail();
$conversation = AiCommonConversation::query()->where('title', 'Concurrent Shared')->firstOrFail();
$source = $conversation->sources()->sole();
$request = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
    'operation_id' => '22222222-2222-4222-8222-222222222222',
    'content' => 'Run exactly once',
    'source_ids' => [$source->id],
]);
echo json_encode(['request' => $request->id, 'state' => $request->state], JSON_THROW_ON_ERROR).PHP_EOL;
