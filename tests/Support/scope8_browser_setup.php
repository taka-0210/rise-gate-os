<?php

declare(strict_types=1);

use App\Models\AiAccessKey;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\ProjectExecution\ProjectExecutionWriter;
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
if ($temporaryDirectory === false || $databaseDirectory !== $temporaryDirectory
    || ! str_starts_with($databaseName, 'company-os-scope8-browser-') || ! str_ends_with($databaseName, '.sqlite')) {
    fwrite(STDERR, "Refusing to prepare a database outside the guarded Scope 8 temp path.\n");
    exit(64);
}
if (is_file($databasePath)) {
    unlink($databasePath);
}
touch($databasePath);

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['app.env' => 'testing', 'app.url' => env('APP_URL', 'http://127.0.0.1:8765'), 'database.default' => 'sqlite', 'database.connections.sqlite.database' => $databasePath, 'product_ux.organization_admission_enabled' => false]);
DB::purge('sqlite');
Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);

$organization = Organization::create(['name' => 'Scope 8 Browser Company', 'slug' => 'scope8-browser-'.strtolower((string) Str::ulid())]);
$owner = User::create(['name' => 'Scope 8 Owner', 'email' => 'scope8-owner@example.test', 'email_verified_at' => now(), 'password' => Hash::make('not-used'), 'is_active' => true]);
$reviewer = User::create(['name' => 'Scope 8 Reviewer', 'email' => 'scope8-reviewer@example.test', 'email_verified_at' => now(), 'password' => Hash::make('not-used'), 'is_active' => true]);
$reader = User::create(['name' => 'Scope 8 Reader', 'email' => 'scope8-reader@example.test', 'email_verified_at' => now(), 'password' => Hash::make('not-used'), 'is_active' => true]);
foreach ([[$owner, 'owner'], [$reviewer, 'member'], [$reader, 'member']] as [$user, $role]) {
    OrganizationUser::create(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role, 'organization_role' => $role, 'membership_status' => 'active', 'access_epoch' => 1, 'lifecycle_version' => 1, 'permissions' => [], 'joined_at' => now()]);
}
$workspace = Workspace::create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id, 'name' => 'Execution Workspace', 'slug' => 'scope8-execution', 'type' => Workspace::TYPE_SHARED, 'status' => Workspace::STATUS_ACTIVE]);
$workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
$workspace->users()->attach($reviewer->id, ['role' => 'member', 'joined_at' => now()]);
$workspace->users()->attach($reader->id, ['role' => 'member', 'joined_at' => now()]);
WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'browser-evidence', 'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES, 'terms_version' => WorkspaceAiSetting::TERMS_VERSION, 'enabled_by' => $owner->id, 'enabled_at' => now()]);

$writer = app(ProjectExecutionWriter::class);
$project = $writer->createProject($owner, $workspace, ['name' => 'Customer Success Launch', 'purpose' => 'Turn the agreed outcome into accountable action.', 'expected_outcome' => 'A reviewed launch with clear ownership.']);
$writer->addMember($owner, $project->fresh(), $reviewer, $workspace, ['member'], $project->fresh()->plan_version);
$roadmap = $writer->createRoadmap($owner, $project->fresh(), ['title' => 'Launch Roadmap', 'purpose' => 'Move from decision to delivery.'], $project->fresh()->plan_version);
$theme = $writer->createTheme($owner, $project->fresh(), $roadmap, ['title' => 'Customer Readiness', 'description' => 'Prepare the team and customers.'], $project->fresh()->plan_version);
$writer->createAction($owner, $project->fresh(), ['improvement_id' => $theme->id, 'title' => 'Publish onboarding guide', 'done_condition' => 'Owner confirms the guide is available.', 'assigned_to' => $owner->id, 'reviewer_user_id' => $reviewer->id, 'due_date' => now()->addDays(14)->toDateString()], $project->fresh()->plan_version);
$writer->createAction($owner, $project->fresh(), ['title' => 'Confirm launch checklist', 'done_condition' => 'Every checklist item has an accountable owner.', 'assigned_to' => $owner->id], $project->fresh()->plan_version);

AiAccessKey::create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'name' => 'Browser Evidence', 'token_hash' => hash('sha256', 'scope8-browser'), 'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE], 'expires_at' => now()->addHour()]);

$project->refresh();
echo json_encode(['database' => $databasePath, 'project_id' => $project->id, 'project_public_id' => $project->public_id, 'plan_version' => $project->plan_version, 'owner_id' => $owner->id, 'reviewer_id' => $reviewer->id], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
