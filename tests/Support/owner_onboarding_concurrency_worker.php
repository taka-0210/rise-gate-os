<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\OwnerOnboarding;
use App\Models\OwnerOnboardingAuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Organization\OwnerOnboardingClaim;
use App\Services\Organization\OwnerOnboardingJourney;
use App\Services\Organization\OwnerOnboardingLegal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$mode = $argv[1] ?? '';
$databasePath = $argv[2] ?? '';
$temporaryDirectory = realpath(sys_get_temp_dir());
$databaseDirectory = $databasePath === '' ? false : realpath(dirname($databasePath));

if ($temporaryDirectory === false
    || $databaseDirectory !== $temporaryDirectory
    || ! str_starts_with(basename($databasePath), 'company-os-scope6-concurrency-')) {
    fwrite(STDERR, "Refusing to use a database outside the Scope 6 temporary fixture.\n");
    exit(64);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => $databasePath,
    'database.connections.sqlite.busy_timeout' => 5000,
    'owner_onboarding.legal_documents_published' => true,
    'owner_onboarding.terms.version' => 'terms-concurrency-v1',
    'owner_onboarding.terms.content_hash' => hash('sha256', 'terms-concurrency-v1'),
    'owner_onboarding.terms.url' => 'https://example.test/terms',
    'owner_onboarding.privacy.version' => 'privacy-concurrency-v1',
    'owner_onboarding.privacy.content_hash' => hash('sha256', 'privacy-concurrency-v1'),
    'owner_onboarding.privacy.url' => 'https://example.test/privacy',
]);
DB::purge('sqlite');

if ($mode === 'seed') {
    if (is_file($databasePath)) {
        unlink($databasePath);
    }
    touch($databasePath);
    Artisan::call('migrate:fresh', ['--database' => 'sqlite', '--force' => true]);
    $issuer = User::query()->create([
        'name' => 'Concurrency Admin',
        'email' => 'scope6-concurrency-admin@example.test',
        'password' => Hash::make('not-used'),
        'is_system_admin' => true,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $owner = User::query()->create([
        'name' => 'Concurrency Owner',
        'email' => 'scope6-concurrency-owner@example.test',
        'password' => Hash::make('not-used'),
        'is_system_admin' => false,
        'is_active' => true,
    ]);
    $owner->forceFill(['email_verified_at' => now()])->save();
    $onboarding = OwnerOnboarding::query()->create([
        'issued_by_user_id' => $issuer->id,
        'normalized_email' => $owner->email,
        'organization_name' => 'Scope 6 Concurrency Company',
        'normalized_organization_name' => 'scope 6 concurrency company',
        'pending_case_key' => hash('sha256', 'scope-6-concurrency-case'),
        'status' => OwnerOnboarding::STATUS_ISSUED,
        'token_hash' => hash('sha256', 'scope-6-concurrency-token'),
        'token_generation' => 1,
        'expires_at' => now()->addDay(),
        'claimed_user_id' => $owner->id,
    ]);
    app(OwnerOnboardingLegal::class)->record($owner, $onboarding);
    echo json_encode([
        'user_id' => $owner->id,
        'onboarding_id' => $onboarding->id,
        'public_id' => $onboarding->public_id,
        'token_hash' => $onboarding->token_hash,
        'generation' => $onboarding->token_generation,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode === 'inspect') {
    $onboardingId = (int) ($argv[3] ?? 0);
    $onboarding = OwnerOnboarding::query()->findOrFail($onboardingId);
    echo json_encode([
        'onboarding_status' => $onboarding->status,
        'organization_id' => $onboarding->completed_organization_id,
        'organizations' => Organization::query()->where('name', 'Scope 6 Concurrency Company')->count(),
        'active_owner_memberships' => OrganizationUser::query()
            ->where('organization_id', $onboarding->completed_organization_id)
            ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->count(),
        'standard_workspaces' => Workspace::query()
            ->where('organization_id', $onboarding->completed_organization_id)
            ->where('type', Workspace::TYPE_SHARED)
            ->where('status', Workspace::STATUS_ACTIVE)
            ->count(),
        'workspace_owner_memberships' => WorkspaceMember::query()
            ->whereIn('workspace_id', Workspace::query()->where('organization_id', $onboarding->completed_organization_id)->select('id'))
            ->where('role', WorkspaceMember::ROLE_OWNER)
            ->count(),
        'completion_audits' => OwnerOnboardingAuditEvent::query()
            ->where('owner_onboarding_id', $onboarding->id)
            ->where('event', 'owner_onboarding.completed')
            ->count(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

if ($mode !== 'complete') {
    fwrite(STDERR, "Unknown mode.\n");
    exit(64);
}

$userId = (int) ($argv[3] ?? 0);
$onboardingId = (int) ($argv[4] ?? 0);
$readyPath = $argv[5] ?? '';
$goPath = $argv[6] ?? '';
$worker = $argv[7] ?? 'unknown';
$onboarding = OwnerOnboarding::query()->findOrFail($onboardingId);
$session = new Store('scope6-concurrency-'.$worker, new ArraySessionHandler(120));
$session->start();
$session->put(OwnerOnboardingClaim::SESSION_KEY, [
    'public_id' => $onboarding->public_id,
    'generation' => $onboarding->token_generation,
    'token_hash' => $onboarding->token_hash,
    'claimed_at' => now()->getTimestamp(),
]);
$request = Request::create('/owner-onboarding/complete', 'POST');
$request->setLaravelSession($session);
touch($readyPath);
$deadline = microtime(true) + 15;
while (! is_file($goPath) && microtime(true) < $deadline) {
    usleep(10_000);
}
if (! is_file($goPath)) {
    fwrite(STDERR, "Barrier timeout.\n");
    exit(70);
}

try {
    $result = app(OwnerOnboardingJourney::class)->complete($request, User::query()->findOrFail($userId));
    echo json_encode([
        'worker' => $worker,
        'status' => 'success',
        'organization_id' => $result->completed_organization_id,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'worker' => $worker,
        'status' => 'failed',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(2);
}
