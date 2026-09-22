<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\OwnerOnboarding;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Organization\OrganizationInvitationAcceptance;
use App\Services\Organization\OwnerOnboardingJourney;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function failWorker(string $message, int $code = 70): never
{
    fwrite(STDERR, $message."\n");
    exit($code);
}

function guardedPath(string $path): string
{
    $temp = str_replace('\\', '/', realpath(sys_get_temp_dir()) ?: '');
    $normalized = str_replace('\\', '/', $path);
    if ($path === '' || ! str_starts_with($normalized, $temp.'/company-os-rg02-')) {
        failWorker('RG02 barrier path rejected.', 64);
    }

    return $path;
}

function waitFor(string $path, float $seconds = 20): void
{
    $deadline = microtime(true) + $seconds;
    while (! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if (! is_file($path)) {
        failWorker('RG02 barrier timeout.');
    }
}

function claimedRequest(string $sessionKey, string $publicId, int $generation, string $token): Request
{
    $request = Request::create('/rg02', 'POST');
    $session = app('session')->driver();
    $session->start();
    $session->put($sessionKey, [
        'public_id' => $publicId,
        'generation' => $generation,
        'token_hash' => hash('sha256', $token),
        'claimed_at' => now()->getTimestamp(),
    ]);
    $request->setLaravelSession($session);

    return $request;
}

$mode = $argv[1] ?? '';
$payload = isset($argv[2]) ? json_decode(base64_decode($argv[2], true) ?: '', true) : null;
if (! is_array($payload)) {
    failWorker('Invalid RG02 worker payload.', 64);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config([
    'product_ux.organization_admission_enabled' => true,
    'mail.default' => 'array',
    'queue.default' => 'sync',
    'session.default' => 'array',
    'cache.default' => 'array',
    'owner_onboarding.legal_documents_published' => true,
    'owner_onboarding.terms.version' => 'rg02-terms-v1',
    'owner_onboarding.terms.content_hash' => str_repeat('a', 64),
    'owner_onboarding.terms.url' => 'https://example.test/terms',
    'owner_onboarding.privacy.version' => 'rg02-privacy-v1',
    'owner_onboarding.privacy.content_hash' => str_repeat('b', 64),
    'owner_onboarding.privacy.url' => 'https://example.test/privacy',
]);
DB::purge();
$profile = DB::selectOne('SELECT VERSION() version, DATABASE() db, @@datadir datadir, CONNECTION_ID() connection_id');
$expectedDatadir = rtrim(str_replace('\\', '/', getenv('RG02_EXPECTED_DATADIR') ?: ''), '/');
if (! str_starts_with((string) $profile->version, '10.11.')
    || ! str_starts_with((string) $profile->db, 'co_rg02_')
    || rtrim(str_replace('\\', '/', (string) $profile->datadir), '/') !== $expectedDatadir
    || getenv('RG02_ALLOW') !== '1') {
    failWorker('RG02 worker connection guard rejected the target.', 65);
}

$startedAt = microtime(true);
$ready = guardedPath((string) ($payload['ready'] ?? ''));
$go = guardedPath((string) ($payload['go'] ?? ''));
touch($ready);
waitFor($go);
usleep((int) ($payload['pre_delay_us'] ?? 0));

try {
    $result = null;
    $diagnostics = [];
    if (in_array($mode, ['admit-existing', 'admit-new'], true)) {
        $user = User::query()->findOrFail((int) $payload['user_id']);
        $entry = (string) $payload['entry'];
        if ($mode === 'admit-existing') {
            $organization = Organization::query()->findOrFail((int) $payload['organization_id']);
            $result = app(ProductOrganizationAdmission::class)->admitExistingOrganization(
                $user,
                $organization,
                $entry,
                function () use ($user, $organization, $payload): string {
                    OrganizationUser::query()->firstOrCreate(
                        ['organization_id' => $organization->id, 'user_id' => $user->id],
                        [
                            'role' => OrganizationUser::ROLE_MEMBER,
                            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
                            'membership_status' => OrganizationUser::STATUS_ACTIVE,
                            'joined_at' => now(),
                        ],
                    );
                    usleep((int) ($payload['hold_us'] ?? 100_000));

                    return (string) $payload['worker'];
                },
            );
        } else {
            $result = app(ProductOrganizationAdmission::class)->admitNewOrganization(
                $user,
                $entry,
                function () use ($user, $payload): array {
                    $organization = Organization::query()->create([
                        'name' => 'RG02 New '.(string) $payload['worker'],
                        'slug' => 'rg02-new-'.strtolower((string) $payload['worker']).'-'.bin2hex(random_bytes(3)),
                    ]);
                    OrganizationUser::query()->create([
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                        'role' => OrganizationUser::ROLE_MEMBER,
                        'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
                        'membership_status' => OrganizationUser::STATUS_ACTIVE,
                        'joined_at' => now(),
                    ]);
                    usleep((int) ($payload['hold_us'] ?? 100_000));

                    return ['result' => (string) $payload['worker'], 'organization' => $organization];
                },
            );
        }
    } elseif ($mode === 's4-accept') {
        $user = User::query()->findOrFail((int) $payload['user_id']);
        $invitation = OrganizationInvitation::query()->findOrFail((int) $payload['invitation_id']);
        $request = claimedRequest(
            'organization_invitation_claim',
            $invitation->public_id,
            $invitation->token_generation,
            (string) $payload['token'],
        );
        $result = app(OrganizationInvitationAcceptance::class)->accept($request, $user)->public_id;
    } elseif ($mode === 's6-complete') {
        $user = User::query()->findOrFail((int) $payload['user_id']);
        $onboarding = OwnerOnboarding::query()->findOrFail((int) $payload['onboarding_id']);
        $request = claimedRequest(
            'owner_onboarding_claim',
            $onboarding->public_id,
            $onboarding->token_generation,
            (string) $payload['token'],
        );
        config(['owner_onboarding.fail_after_step' => $payload['fail_after_step'] ?? null]);
        $result = app(OwnerOnboardingJourney::class)->complete($request, $user)->public_id;
    } elseif ($mode === 'sa-http') {
        $admin = User::query()->findOrFail((int) $payload['admin_id']);
        $target = User::query()->findOrFail((int) $payload['user_id']);
        $workspace = Workspace::query()->findOrFail((int) $payload['workspace_id']);
        $request = Request::create(
            '/system-admin/members/'.$target->getRouteKey().'/workspaces',
            'POST',
            ['workspace_id' => $workspace->id, 'workspace_role' => WorkspaceMember::ROLE_MEMBER],
        );
        $session = app('session')->driver();
        $session->start();
        $session->put('access_mode', 'system_admin');
        $session->put('credential_generation', $admin->credential_generation);
        $request->setLaravelSession($session);
        $app->instance('request', $request);
        Auth::guard('web')->setUser($admin);
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
        $result = ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location')];
        if ($response->getStatusCode() >= 500) {
            throw new RuntimeException('SA HTTP route returned '.$response->getStatusCode());
        }
    } elseif ($mode === 'deadlock-admission') {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
        $user = User::query()->findOrFail((int) $payload['user_id']);
        $organization = Organization::query()->findOrFail((int) $payload['target_organization_id']);
        $first = (int) $payload['first_lock_id'];
        $second = (int) $payload['second_lock_id'];
        $attempts = 0;
        $result = app(ProductOrganizationAdmission::class)->admitExistingOrganization(
            $user,
            $organization,
            ProductOrganizationAdmission::ENTRY_SYSTEM_ADMIN_WORKSPACE,
            function () use ($first, $second, $payload, &$attempts): string {
                $attempts++;
                return DB::transaction(function () use ($first, $second, $payload, $attempts): string {
                    Organization::query()->whereKey($first)->lockForUpdate()->firstOrFail();
                    if ($attempts === 1) {
                        touch(guardedPath((string) $payload['locked']));
                        waitFor(guardedPath((string) $payload['both_locked']));
                    }
                    Organization::query()->whereKey($second)->lockForUpdate()->firstOrFail();

                    return (string) $payload['worker'];
                });
            },
        );
        $diagnostics['callback_attempts'] = $attempts;
    } elseif ($mode === 'timeout-admission') {
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
        $user = User::query()->findOrFail((int) $payload['user_id']);
        $organization = Organization::query()->findOrFail((int) $payload['target_organization_id']);
        $lockedOrganization = (int) $payload['locked_organization_id'];
        $attempts = 0;
        $result = app(ProductOrganizationAdmission::class)->admitExistingOrganization(
            $user,
            $organization,
            ProductOrganizationAdmission::ENTRY_SYSTEM_ADMIN_WORKSPACE,
            function () use ($lockedOrganization, $payload, &$attempts): string {
                $attempts++;
                return DB::transaction(function () use ($lockedOrganization, $payload): string {
                    Organization::query()->create([
                        'name' => 'RG02 timeout marker',
                        'slug' => 'rg02-timeout-marker',
                    ]);
                    Organization::query()->whereKey($lockedOrganization)->lockForUpdate()->firstOrFail();

                    return (string) $payload['worker'];
                });
            },
        );
        $diagnostics['callback_attempts'] = $attempts;
    } else {
        throw new LogicException('Unknown RG02 worker mode.');
    }

    echo json_encode([
        'status' => 'success',
        'worker' => $payload['worker'] ?? 'worker',
        'result' => $result,
        'connection_id' => $profile->connection_id,
        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        'diagnostics' => $diagnostics,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
} catch (ValidationException $exception) {
    echo json_encode([
        'status' => 'rejected',
        'worker' => $payload['worker'] ?? 'worker',
        'exception' => $exception::class,
        'errors' => array_keys($exception->errors()),
        'connection_id' => $profile->connection_id,
        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(2);
} catch (QueryException $exception) {
    $error = $exception->errorInfo;
    echo json_encode([
        'status' => 'sql_error',
        'worker' => $payload['worker'] ?? 'worker',
        'sqlstate' => $error[0] ?? $exception->getCode(),
        'driver_code' => $error[1] ?? null,
        'connection_id' => $profile->connection_id,
        'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(3);
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'harness_error',
        'worker' => $payload['worker'] ?? 'worker',
        'exception' => $exception::class,
        'message' => $exception->getMessage(),
        'connection_id' => $profile->connection_id,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(70);
}
