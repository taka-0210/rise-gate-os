<?php

declare(strict_types=1);

use App\Models\AiCommonConversation;
use App\Models\AiProposal;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiCommon\AiCommonProposalContract;
use App\Services\AiCommon\AiCommonProposalFactory;
use App\Services\AiProposalApplier;
use App\Services\AiProposalApprover;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$version = (string) DB::selectOne('select version() as value')->value;
$datadir = str_replace('\\', '/', (string) DB::selectOne('select @@datadir as value')->value);
if (config('database.default') !== 'mysql'
    || config('database.connections.mysql.host') !== '127.0.0.1'
    || (int) config('database.connections.mysql.port') !== 13339
    || config('database.connections.mysql.database') !== 'co_scope11_ai'
    || ! str_starts_with($version, '10.11.')
    || ! str_contains($datadir, '/company-os-scope11-mariadb-')) {
    throw new RuntimeException('Scope 11 MariaDB guard rejected target.');
}

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    $owner = User::create([
        'name' => 'Scope 11 Owner', 'email' => 'scope11-owner@example.test',
        'password' => Hash::make('isolated'), 'is_active' => true,
    ]);
    $recipient = User::create([
        'name' => 'Scope 11 Recipient', 'email' => 'scope11-recipient@example.test',
        'password' => Hash::make('isolated'), 'is_active' => true,
    ]);
    $organization = Organization::create(['name' => 'Scope 11 Isolated', 'slug' => 'scope11-isolated']);
    $workspace = Workspace::create([
        'organization_id' => $organization->id, 'owner_user_id' => $owner->id,
        'name' => 'Scope 11', 'slug' => 'scope11', 'status' => Workspace::STATUS_ACTIVE,
    ]);
    foreach ([[$owner, 'owner'], [$recipient, 'member']] as [$user, $role]) {
        OrganizationUser::create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => $role, 'organization_role' => $role,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1, 'lifecycle_version' => 1, 'permissions' => [], 'joined_at' => now(),
        ]);
        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        ProductAccountEligibility::create([
            'user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
            'classification_version' => 'scope11-mariadb', 'classified_at' => now(),
            'evidence_ref' => 'scope11-mariadb',
        ]);
    }
    OrganizationAiPolicy::create([
        'organization_id' => $organization->id, 'is_enabled' => true,
        'allowed_categories' => ['common_entry', 'project', 'action', 'business_domain', 'capture'],
        'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
    ]);
    WorkspaceAiSetting::create([
        'workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai',
        'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
        'terms_version' => WorkspaceAiSetting::TERMS_VERSION,
        'enabled_by' => $owner->id, 'enabled_at' => now(),
    ]);
    $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, [
        'name' => 'Scope 11 Concurrency', 'purpose' => 'Synthetic', 'expected_outcome' => 'Exactly once',
    ]);
    app(ProjectExecutionWriter::class)->addMember(
        $owner, $project->fresh(), $recipient, $workspace, ['member'], $project->fresh()->plan_version,
    );
    $conversation = AiCommonConversation::create([
        'organization_id' => $organization->id, 'user_id' => $owner->id,
        'title' => 'MariaDB concurrency', 'status' => 'active', 'version' => 1,
    ]);
    $proposal = app(AiCommonProposalFactory::class)->create($owner, $organization, $conversation, [
        'operation' => AiCommonProposalContract::CAPTURE_CREATE,
        'title' => 'Exactly once Capture',
        'idempotency_key' => 'scope11-mariadb-concurrency',
        'attributes' => [
            'type' => 'request', 'body' => 'Synthetic MariaDB concurrency evidence',
            'recipient_user_id' => $recipient->id, 'notification_timing' => 'now',
            'recipient_confirmed' => true,
        ],
    ]);
    app(AiProposalApprover::class)->approve($proposal, $owner);
    echo json_encode(['proposal' => $proposal->public_id], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($mode === 'apply') {
    $barrier = $argv[2] ?? '';
    while (! is_file($barrier)) {
        usleep(20000);
    }
    $owner = User::where('email', 'scope11-owner@example.test')->firstOrFail();
    $proposal = AiProposal::where('title', 'Exactly once Capture')->firstOrFail();
    $result = app(AiProposalApplier::class)->apply($proposal, $owner);
    echo json_encode(['status' => $result->status], JSON_THROW_ON_ERROR);
    exit(0);
}

if ($mode === 'inspect') {
    echo json_encode([
        'captures' => DB::table('captures')->count(),
        'capture_events' => DB::table('capture_events')->count(),
        'notifications' => DB::table('company_notifications')->count(),
        'attempts' => DB::table('ai_proposal_apply_attempts')->count(),
        'applied_attempts' => DB::table('ai_proposal_apply_attempts')->where('status', 'applied')->count(),
        'failed_attempts' => DB::table('ai_proposal_apply_attempts')->where('status', 'failed')->count(),
        'item_results' => DB::table('ai_proposal_item_results')->count(),
    ], JSON_THROW_ON_ERROR);
    exit(0);
}

throw new RuntimeException('Unknown Scope 11 worker mode.');
