<?php

declare(strict_types=1);

use App\Http\Controllers\InvitationOnboardingController;
use App\Mail\AccountActionMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembershipLifecycleOperation;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\BusinessDomain\BusinessDomainAccess;
use App\Services\Company\CompanyAccess;
use App\Services\Organization\OrganizationAccess;
use App\Services\Organization\OrganizationAdministration;
use App\Services\Organization\OrganizationInvitationAcceptance;
use App\Services\Organization\OrganizationInvitationClaim;
use App\Services\Organization\OrganizationInvitationService;
use App\Services\Organization\OrganizationMembershipLifecycle;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

const AS_G03_OLD_USER_ID = 1;
const AS_G03_KEEP_ORGANIZATION_ID = 1;
const AS_G03_TARGET_ORGANIZATION_ID = 4;
const AS_G03_KEEP_MEMBERSHIP_ID = 1;
const AS_G03_TARGET_MEMBERSHIP_ID = 5;
const AS_G03_STANDARD_WORKSPACE_ID = 5;
const AS_G03_NEW_EMAIL = 'takami@pro-chubo.com';
const AS_G03_ISSUE_REQUEST_ID = '7c36cc6f-0ef8-43f2-8cd3-fdfab1651003';
const AS_G03_CUTOVER_REQUEST_ID = 'a5492884-824b-43b0-85a0-a3bcdb979003';
const AS_G03_COMPENSATION_REQUEST_ID = 'cb4a487c-987c-49d9-a69e-451073039003';
const AS_G03_FINALIZE_REQUEST_ID = '8da5648a-b83f-4d30-b03d-b54620309003';
const AS_G03_REOPEN_REQUEST_ID = '43beca49-8f4f-4765-a560-e4bb5f309003';

function failRehearsal(string $message, int $code = 70): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit($code);
}

function assertRehearsal(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function sqlitePdo(string $path): PDO
{
    $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}

function allTableFingerprint(PDO $pdo): array
{
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
        ->fetchAll(PDO::FETCH_COLUMN);
    $result = [];
    foreach ($tables as $table) {
        if ($table === 'migrations') {
            continue;
        }
        $quotedTable = '"'.str_replace('"', '""', $table).'"';
        $schema = $pdo->query('PRAGMA table_info('.$quotedTable.')')->fetchAll(PDO::FETCH_ASSOC);
        $columns = array_column($schema, 'name');
        $quotedColumns = implode(', ', array_map(
            static fn (string $column): string => '"'.str_replace('"', '""', $column).'"',
            $columns,
        ));
        $order = in_array('id', $columns, true) ? '"id"' : 'rowid';
        $statement = $pdo->query("SELECT {$quotedColumns} FROM {$quotedTable} ORDER BY {$order}");
        $context = hash_init('sha256');
        $count = 0;
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            hash_update($context, json_encode($row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)."\n");
            $count++;
        }
        $result[$table] = ['count' => $count, 'hash' => hash_final($context)];
    }

    return $result;
}

function copySnapshot(string $source, string $target): void
{
    if (is_file($target)) {
        failRehearsal('Refusing to overwrite an existing AS-G03 snapshot.');
    }
    $pdo = sqlitePdo($source);
    $integrity = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    assertRehearsal($integrity === 'ok', 'Source SQLite integrity check failed.');
    $quoted = $pdo->quote(str_replace('\\', '/', $target));
    $pdo->exec('VACUUM INTO '.$quoted);
    unset($pdo);
    assertRehearsal(is_file($target) && filesize($target) > 0, 'Consistent SQLite snapshot was not created.');
    assertRehearsal(sqlitePdo($target)->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Snapshot integrity check failed.');
}

function cloneSnapshot(string $snapshot, string $target): void
{
    if (is_file($target) || ! copy($snapshot, $target)) {
        failRehearsal('Unable to create guarded AS-G03 scenario clone.');
    }
    assertRehearsal(sqlitePdo($target)->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Scenario clone integrity check failed.');
}

function removeGuardedRunDirectory(string $directory): void
{
    $temp = realpath(sys_get_temp_dir());
    $resolved = realpath($directory);
    assertRehearsal($temp !== false && $resolved !== false
        && dirname($resolved) === $temp
        && str_starts_with(basename($resolved), 'company-os-as-g03-'), 'Temporary cleanup guard rejected the run directory.');
    foreach (scandir($resolved) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $resolved.DIRECTORY_SEPARATOR.$entry;
        assertRehearsal(is_file($path) && unlink($path), 'Unable to remove temporary AS-G03 artifact.');
    }
    assertRehearsal(rmdir($resolved), 'Unable to remove the temporary AS-G03 directory.');
}

function bootClone(string $clone): void
{
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$clone);
    putenv('PRODUCT_ORGANIZATION_ADMISSION_ENABLED=true');
    putenv('ACCOUNT_MAIL_MAILER=array');
    putenv('MAIL_MAILER=array');
    putenv('QUEUE_CONNECTION=sync');
    putenv('SESSION_DRIVER=array');
    putenv('CACHE_STORE=array');

    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config([
        'app.env' => 'testing',
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $clone,
        'product_ux.organization_admission_enabled' => true,
        'account.mail.mailer' => 'array',
        'mail.default' => 'array',
        'mail.mailers.array.transport' => 'array',
        'queue.default' => 'sync',
        'session.default' => 'array',
        'cache.default' => 'array',
    ]);
    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');
    assertRehearsal((string) DB::connection()->getDatabaseName() === $clone, 'Laravel did not bind to the guarded clone.');
    assertRehearsal(str_starts_with(basename($clone), 'company-os-as-g03-'), 'Clone filename guard failed.');
}

function selectClone(string $clone): void
{
    assertRehearsal(is_file($clone) && str_starts_with(basename($clone), 'company-os-as-g03-'), 'Scenario clone switch guard failed.');
    Auth::logout();
    DB::disconnect('sqlite');
    config(['database.connections.sqlite.database' => $clone]);
    DB::purge('sqlite');
    DB::setDefaultConnection('sqlite');
    assertRehearsal((string) DB::connection()->getDatabaseName() === $clone, 'Laravel did not switch to the guarded scenario clone.');
}

function newRequest(array $input = []): Request
{
    $request = Request::create('/as-g03', 'POST', $input);
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);

    return $request;
}

function rowSnapshot(): array
{
    $old = User::query()->findOrFail(AS_G03_OLD_USER_ID);
    $orgKeep = Organization::query()->findOrFail(AS_G03_KEEP_ORGANIZATION_ID);
    $orgTarget = Organization::query()->findOrFail(AS_G03_TARGET_ORGANIZATION_ID);
    $keepMembership = OrganizationUser::query()->findOrFail(AS_G03_KEEP_MEMBERSHIP_ID);
    $targetMembership = OrganizationUser::query()->findOrFail(AS_G03_TARGET_MEMBERSHIP_ID);
    $workspace = Workspace::query()->findOrFail(AS_G03_STANDARD_WORKSPACE_ID);
    $eligibility = ProductAccountEligibility::query()->where('user_id', $old->id)->firstOrFail();
    $workspaceMembership = WorkspaceMember::query()
        ->where('workspace_id', $workspace->id)
        ->where('user_id', $old->id)
        ->firstOrFail();

    return [
        'old_user' => [
            'id' => $old->id,
            'active' => (bool) $old->is_active,
            'system_admin' => (bool) $old->is_system_admin,
        ],
        'keep_organization' => ['id' => $orgKeep->id, 'membership_id' => $keepMembership->id],
        'target_organization' => [
            'id' => $orgTarget->id,
            'membership_id' => $targetMembership->id,
            'standard_workspace_id' => $orgTarget->standard_workspace_id,
        ],
        'target_membership' => [
            'status' => $targetMembership->membership_status,
            'organization_role' => $targetMembership->organization_role,
            'legacy_role' => $targetMembership->role,
            'legacy_company_role' => $targetMembership->company_role,
            'permissions' => $targetMembership->permissions ?? [],
            'access_epoch' => $targetMembership->access_epoch,
            'lifecycle_version' => $targetMembership->lifecycle_version,
        ],
        'workspace' => [
            'id' => $workspace->id,
            'organization_id' => $workspace->organization_id,
            'owner_user_id' => $workspace->owner_user_id,
            'old_user_role' => $workspaceMembership->role,
        ],
        'eligibility' => [
            'id' => $eligibility->id,
            'mode' => $eligibility->mode,
            'product_organization_id' => $eligibility->product_organization_id,
            'classification_version' => $eligibility->classification_version,
            'compatibility_membership_ids' => $eligibility->compatibilities()
                ->orderBy('organization_user_id')->pluck('organization_user_id')->all(),
        ],
        'counts' => [
            'users' => User::query()->count(),
            'organizations' => Organization::query()->count(),
            'workspaces' => Workspace::query()->count(),
            'projects' => DB::table('projects')->count(),
            'active_target_owners' => OrganizationUser::query()
                ->where('organization_id', AS_G03_TARGET_ORGANIZATION_ID)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
                ->count(),
        ],
    ];
}

function preflight(): array
{
    $state = rowSnapshot();
    assertRehearsal($state['old_user'] === ['id' => 1, 'active' => true, 'system_admin' => true], 'Old account precondition changed.');
    assertRehearsal($state['keep_organization']['membership_id'] === AS_G03_KEEP_MEMBERSHIP_ID, 'Keep membership changed.');
    assertRehearsal($state['target_organization']['membership_id'] === AS_G03_TARGET_MEMBERSHIP_ID, 'Target membership changed.');
    assertRehearsal(
        in_array($state['target_organization']['standard_workspace_id'], [null, AS_G03_STANDARD_WORKSPACE_ID], true),
        'A different Standard Workspace is already configured: '.json_encode($state['target_organization'], JSON_THROW_ON_ERROR),
    );
    assertRehearsal($state['workspace']['organization_id'] === AS_G03_TARGET_ORGANIZATION_ID, 'Workspace tenant boundary changed.');
    assertRehearsal($state['workspace']['owner_user_id'] === AS_G03_OLD_USER_ID, 'Workspace owner precondition changed.');
    assertRehearsal($state['target_membership']['status'] === OrganizationUser::STATUS_ACTIVE, 'Old target membership is not active.');
    assertRehearsal($state['target_membership']['organization_role'] === OrganizationUser::ORGANIZATION_ROLE_OWNER, 'Old target membership is not Owner.');
    assertRehearsal($state['eligibility']['mode'] === ProductAccountEligibility::MODE_LEGACY_MULTI, 'Old eligibility is not legacy_multi.');
    assertRehearsal($state['eligibility']['compatibility_membership_ids'] === [1, 5], 'Legacy compatibility set changed.');
    assertRehearsal($state['counts']['active_target_owners'] === 1, 'Target organization Owner count precondition changed.');

    $email = strtolower(AS_G03_NEW_EMAIL);
    $collisions = [
        'users' => User::query()->whereRaw('LOWER(email) = ?', [$email])->count(),
        'pending_email_changes' => DB::table('account_email_requests')->whereRaw('LOWER(pending_email) = ?', [$email])->count(),
        'pending_invitations' => OrganizationInvitation::query()
            ->where('pending_email_key', $email)
            ->where('status', OrganizationInvitation::STATUS_PENDING)
            ->count(),
    ];
    assertRehearsal(array_sum($collisions) === 0, 'New email collision detected.');

    return ['state' => $state, 'email_collisions' => $collisions];
}

function prepareApprovedStandardWorkspace(): array
{
    return DB::transaction(function (): array {
        $organization = Organization::query()->lockForUpdate()->findOrFail(AS_G03_TARGET_ORGANIZATION_ID);
        $workspace = Workspace::query()->lockForUpdate()->findOrFail(AS_G03_STANDARD_WORKSPACE_ID);
        assertRehearsal($workspace->organization_id === $organization->id, 'Approved Standard Workspace belongs to another organization.');
        assertRehearsal($workspace->status === Workspace::STATUS_ACTIVE && $workspace->type === Workspace::TYPE_SHARED, 'Approved Standard Workspace is not an active shared Workspace.');
        assertRehearsal(WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', AS_G03_OLD_USER_ID)
            ->where('role', WorkspaceMember::ROLE_OWNER)
            ->exists(), 'Current Owner does not own the approved Standard Workspace.');

        $before = $organization->standard_workspace_id;
        if ($before === null) {
            $updated = Organization::query()
                ->whereKey($organization->id)
                ->whereNull('standard_workspace_id')
                ->update(['standard_workspace_id' => $workspace->id, 'updated_at' => now()]);
            assertRehearsal($updated === 1, 'Conditional Standard Workspace selection failed.');
        }
        assertRehearsal(Organization::query()->findOrFail($organization->id)->standard_workspace_id === $workspace->id, 'Standard Workspace did not converge to the approved ID.');

        return ['before' => $before, 'after' => $workspace->id, 'created_workspace' => false];
    });
}

function createAndAcceptNewOwner(): array
{
    Mail::fake();
    Auth::logout();
    $old = User::query()->findOrFail(AS_G03_OLD_USER_ID);
    $organization = Organization::query()->findOrFail(AS_G03_TARGET_ORGANIZATION_ID);
    $invitation = app(OrganizationInvitationService::class)->issue(
        $old,
        $organization,
        AS_G03_NEW_EMAIL,
        OrganizationUser::ORGANIZATION_ROLE_OWNER,
        [],
        AS_G03_ISSUE_REQUEST_ID,
    );
    $mail = Mail::sent(AccountActionMail::class)->last();
    assertRehearsal($mail instanceof AccountActionMail, 'Invitation mail was not captured by the fake transport.');
    $query = [];
    parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);
    $token = (string) ($query['token'] ?? '');
    assertRehearsal($token !== '' && hash_equals($invitation->token_hash, hash('sha256', $token)), 'Captured invitation token does not match.');

    $request = newRequest([
        'name' => User::query()->findOrFail(AS_G03_OLD_USER_ID)->name,
        'password' => 'AS-G03-'.Str::random(40),
    ]);
    $request->merge(['password_confirmation' => $request->input('password')]);
    app(OrganizationInvitationClaim::class)->remember($request, $invitation, $token);
    app(InvitationOnboardingController::class)->register(
        $request,
        app(OrganizationInvitationClaim::class),
        app(OrganizationInvitationAcceptance::class),
        app(ProductOrganizationAdmission::class),
    );

    $newUser = User::query()->whereRaw('LOWER(email) = ?', [strtolower(AS_G03_NEW_EMAIL)])->sole();
    $membership = OrganizationUser::query()
        ->where('organization_id', AS_G03_TARGET_ORGANIZATION_ID)
        ->where('user_id', $newUser->id)
        ->sole();
    assertRehearsal(! $newUser->is_system_admin, 'New account unexpectedly received System Admin.');
    assertRehearsal($newUser->email_verified_at === null, 'New account was implicitly verified.');
    assertRehearsal($membership->membership_status === OrganizationUser::STATUS_INVITED, 'Membership activated before verification.');
    assertRehearsal($membership->organization_role === null, 'Invited membership received an active role early.');

    $verificationRejected = false;
    try {
        app(OrganizationInvitationAcceptance::class)->accept($request, $newUser);
    } catch (ValidationException $exception) {
        $verificationRejected = array_key_exists('email', $exception->errors());
    }
    assertRehearsal($verificationRejected, 'Unverified invitation acceptance was not rejected.');
    assertRehearsal(rowSnapshot()['counts']['active_target_owners'] === 1, 'Pending Owner incorrectly counted as active.');

    $lastOwnerProtected = false;
    try {
        app(OrganizationAdministration::class)->updateRole(
            $old,
            $organization,
            OrganizationUser::query()->findOrFail(AS_G03_TARGET_MEMBERSHIP_ID),
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
        );
    } catch (ValidationException $exception) {
        $lastOwnerProtected = array_key_exists('organization_role', $exception->errors());
    }
    assertRehearsal($lastOwnerProtected, 'Last active Owner demotion was not rejected.');

    // External Email Verification boundary, simulated only in the guarded clone.
    $newUser->forceFill(['email_verified_at' => now()])->save();
    $accepted = app(OrganizationInvitationAcceptance::class)->accept($request, $newUser->fresh());
    app(OrganizationInvitationAcceptance::class)->accept($request, $newUser->fresh());
    $membership->refresh();
    $workspaceMembership = WorkspaceMember::query()
        ->where('workspace_id', AS_G03_STANDARD_WORKSPACE_ID)
        ->where('user_id', $newUser->id)
        ->sole();
    $eligibility = ProductAccountEligibility::query()->where('user_id', $newUser->id)->sole();
    assertRehearsal($accepted->status === OrganizationInvitation::STATUS_ACCEPTED, 'Invitation did not reach accepted.');
    assertRehearsal($membership->membership_status === OrganizationUser::STATUS_ACTIVE, 'New membership did not activate.');
    assertRehearsal($membership->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER, 'New Organization Owner role is missing.');
    assertRehearsal($membership->role === OrganizationUser::ROLE_MEMBER, 'S4 unexpectedly promoted legacy role.');
    assertRehearsal($membership->company_role === OrganizationUser::COMPANY_ROLE_MEMBER, 'S4 unexpectedly promoted company role.');
    assertRehearsal($workspaceMembership->role === WorkspaceMember::ROLE_MEMBER, 'S4 unexpectedly promoted Workspace role.');
    assertRehearsal($eligibility->mode === ProductAccountEligibility::MODE_SINGLE
        && $eligibility->product_organization_id === AS_G03_TARGET_ORGANIZATION_ID, 'New eligibility did not bind to target single.');

    return [
        'user_id' => $newUser->id,
        'membership_id' => $membership->id,
        'workspace_membership_id' => $workspaceMembership->id,
        'eligibility_id' => $eligibility->id,
        'invitation_id' => $accepted->id,
        'verification_rejected_before_confirmation' => $verificationRejected,
        'last_owner_protected_before_acceptance' => $lastOwnerProtected,
        'mail_transport' => 'fake',
    ];
}

function cutoverPayloadHash(int $newUserId, array $preflight): string
{
    return hash('sha256', json_encode([
        'case' => 'AS-G03',
        'old_user_id' => AS_G03_OLD_USER_ID,
        'new_user_id' => $newUserId,
        'keep_organization_id' => AS_G03_KEEP_ORGANIZATION_ID,
        'target_organization_id' => AS_G03_TARGET_ORGANIZATION_ID,
        'target_membership_id' => AS_G03_TARGET_MEMBERSHIP_ID,
        'standard_workspace_id' => AS_G03_STANDARD_WORKSPACE_ID,
        'permissions' => $preflight['state']['target_membership']['permissions'],
    ], JSON_THROW_ON_ERROR));
}

function executeCutover(int $newUserId, array $preflight, string $payloadHash, bool $injectFailure = false): array
{
    return DB::transaction(function () use ($newUserId, $preflight, $payloadHash, $injectFailure): array {
        $organization = Organization::query()->lockForUpdate()->findOrFail(AS_G03_TARGET_ORGANIZATION_ID);
        $workspace = Workspace::query()->lockForUpdate()->findOrFail(AS_G03_STANDARD_WORKSPACE_ID);
        $oldMembership = OrganizationUser::query()->lockForUpdate()->findOrFail(AS_G03_TARGET_MEMBERSHIP_ID);
        $newMembership = OrganizationUser::query()
            ->where('organization_id', AS_G03_TARGET_ORGANIZATION_ID)
            ->where('user_id', $newUserId)
            ->lockForUpdate()->sole();
        $oldEligibility = ProductAccountEligibility::query()
            ->where('user_id', AS_G03_OLD_USER_ID)->lockForUpdate()->sole();
        $newEligibility = ProductAccountEligibility::query()
            ->where('user_id', $newUserId)->lockForUpdate()->sole();
        $newWorkspaceMembership = WorkspaceMember::query()
            ->where('workspace_id', AS_G03_STANDARD_WORKSPACE_ID)
            ->where('user_id', $newUserId)
            ->lockForUpdate()->sole();

        assertRehearsal($organization->standard_workspace_id === AS_G03_STANDARD_WORKSPACE_ID, 'Target standard Workspace changed during cutover.');
        assertRehearsal($workspace->organization_id === AS_G03_TARGET_ORGANIZATION_ID, 'Workspace tenant changed during cutover.');
        assertRehearsal($newMembership->membership_status === OrganizationUser::STATUS_ACTIVE
            && $newMembership->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER, 'New active Owner is not ready.');
        assertRehearsal($newEligibility->mode === ProductAccountEligibility::MODE_SINGLE
            && $newEligibility->product_organization_id === AS_G03_TARGET_ORGANIZATION_ID, 'New account eligibility changed.');

        $alreadyApplied = $oldMembership->membership_status === OrganizationUser::STATUS_SUSPENDED
            && $oldEligibility->mode === ProductAccountEligibility::MODE_SINGLE
            && $oldEligibility->product_organization_id === AS_G03_KEEP_ORGANIZATION_ID
            && $workspace->owner_user_id === $newUserId
            && $newWorkspaceMembership->role === WorkspaceMember::ROLE_OWNER
            && $newMembership->role === OrganizationUser::ROLE_OWNER
            && $newMembership->company_role === OrganizationUser::COMPANY_ROLE_OWNER;

        $newMembership->forceFill([
            'role' => OrganizationUser::ROLE_OWNER,
            'company_role' => OrganizationUser::COMPANY_ROLE_OWNER,
            'permissions' => $preflight['state']['target_membership']['permissions'],
        ])->save();
        $newWorkspaceMembership->forceFill(['role' => WorkspaceMember::ROLE_OWNER])->save();
        $workspace->forceFill(['owner_user_id' => $newUserId])->save();

        $reason = 'AS-G03 manifest '.substr($payloadHash, 0, 32);
        $operation = app(OrganizationMembershipLifecycle::class)->execute(
            User::query()->findOrFail($newUserId),
            $organization,
            $oldMembership,
            OrganizationMembershipLifecycle::COMMAND_SUSPEND,
            $reason,
            (int) $preflight['state']['target_membership']['lifecycle_version'],
            AS_G03_CUTOVER_REQUEST_ID,
        );

        if (! $alreadyApplied) {
            $updated = ProductAccountEligibility::query()
                ->whereKey($oldEligibility->id)
                ->where('mode', ProductAccountEligibility::MODE_LEGACY_MULTI)
                ->whereNull('product_organization_id')
                ->update([
                    'mode' => ProductAccountEligibility::MODE_SINGLE,
                    'product_organization_id' => AS_G03_KEEP_ORGANIZATION_ID,
                    'classified_at' => now(),
                    'evidence_ref' => 'account-separation:'.substr($payloadHash, 0, 48),
                    'updated_at' => now(),
                ]);
            assertRehearsal($updated === 1, 'Old eligibility conditional update failed.');
        }

        if ($injectFailure) {
            throw new RuntimeException('AS-G03 injected failure after all cutover mutations.');
        }

        return ['operation_id' => $operation->id, 'already_applied' => $alreadyApplied];
    }, 3);
}

function compensateSuspendedCutover(int $newUserId, array $preflight, string $payloadHash): array
{
    return DB::transaction(function () use ($newUserId, $payloadHash): array {
        $organization = Organization::query()->lockForUpdate()->findOrFail(AS_G03_TARGET_ORGANIZATION_ID);
        $workspace = Workspace::query()->lockForUpdate()->findOrFail(AS_G03_STANDARD_WORKSPACE_ID);
        $oldMembership = OrganizationUser::query()->lockForUpdate()->findOrFail(AS_G03_TARGET_MEMBERSHIP_ID);
        $newMembership = OrganizationUser::query()
            ->where('organization_id', AS_G03_TARGET_ORGANIZATION_ID)
            ->where('user_id', $newUserId)->lockForUpdate()->sole();
        $oldEligibility = ProductAccountEligibility::query()
            ->where('user_id', AS_G03_OLD_USER_ID)->lockForUpdate()->sole();
        $newWorkspaceMembership = WorkspaceMember::query()
            ->where('workspace_id', AS_G03_STANDARD_WORKSPACE_ID)
            ->where('user_id', $newUserId)->lockForUpdate()->sole();

        assertRehearsal($oldMembership->membership_status === OrganizationUser::STATUS_SUSPENDED, 'Compensation requires the old membership to be suspended.');
        assertRehearsal($oldEligibility->mode === ProductAccountEligibility::MODE_SINGLE
            && $oldEligibility->product_organization_id === AS_G03_KEEP_ORGANIZATION_ID, 'Compensation eligibility precondition changed.');

        $operation = app(OrganizationMembershipLifecycle::class)->execute(
            User::query()->findOrFail($newUserId),
            $organization,
            $oldMembership,
            OrganizationMembershipLifecycle::COMMAND_RESUME,
            'AS-G03 compensation '.substr($payloadHash, 0, 32),
            (int) $oldMembership->lifecycle_version,
            AS_G03_COMPENSATION_REQUEST_ID,
        );
        $workspace->forceFill(['owner_user_id' => AS_G03_OLD_USER_ID])->save();
        $newWorkspaceMembership->forceFill(['role' => WorkspaceMember::ROLE_MEMBER])->save();
        $newMembership->forceFill([
            'role' => OrganizationUser::ROLE_MEMBER,
            'company_role' => OrganizationUser::COMPANY_ROLE_MEMBER,
            'permissions' => [],
        ])->save();
        $updated = ProductAccountEligibility::query()
            ->whereKey($oldEligibility->id)
            ->where('mode', ProductAccountEligibility::MODE_SINGLE)
            ->where('product_organization_id', AS_G03_KEEP_ORGANIZATION_ID)
            ->update([
                'mode' => ProductAccountEligibility::MODE_LEGACY_MULTI,
                'product_organization_id' => null,
                'classified_at' => now(),
                'evidence_ref' => 'account-separation-compensation:'.substr($payloadHash, 0, 35),
                'updated_at' => now(),
            ]);
        assertRehearsal($updated === 1, 'Compensation eligibility update failed.');

        return ['operation_id' => $operation->id, 'result_status' => $operation->result_status];
    }, 3);
}

function finalizeLeftAndRejectReopen(int $newUserId): array
{
    $organization = Organization::query()->findOrFail(AS_G03_TARGET_ORGANIZATION_ID);
    $target = OrganizationUser::query()->findOrFail(AS_G03_TARGET_MEMBERSHIP_ID);
    $actor = User::query()->findOrFail($newUserId);
    $operation = app(OrganizationMembershipLifecycle::class)->execute(
        $actor,
        $organization,
        $target,
        OrganizationMembershipLifecycle::COMMAND_END,
        'AS-G03 acceptance completed; finalize old target membership',
        (int) $target->lifecycle_version,
        AS_G03_FINALIZE_REQUEST_ID,
    );
    app(OrganizationMembershipLifecycle::class)->execute(
        $actor,
        $organization,
        $target->fresh(),
        OrganizationMembershipLifecycle::COMMAND_END,
        'AS-G03 acceptance completed; finalize old target membership',
        (int) $target->lifecycle_version,
        AS_G03_FINALIZE_REQUEST_ID,
    );
    $reopenRejected = false;
    try {
        app(OrganizationMembershipLifecycle::class)->execute(
            $actor,
            $organization,
            $target->fresh(),
            OrganizationMembershipLifecycle::COMMAND_RESUME,
            'AS-G03 must not reopen a left membership',
            (int) $target->fresh()->lifecycle_version,
            AS_G03_REOPEN_REQUEST_ID,
        );
    } catch (ValidationException $exception) {
        $reopenRejected = array_key_exists('command', $exception->errors());
    }
    assertRehearsal($reopenRejected, 'A left membership was reopened automatically.');
    assertRehearsal($target->fresh()->membership_status === OrganizationUser::STATUS_LEFT, 'Final membership did not reach left.');
    assertRehearsal(OrganizationMembershipLifecycleOperation::query()
        ->where('request_id', AS_G03_FINALIZE_REQUEST_ID)->count() === 1, 'Finalize retry duplicated its receipt.');

    return [
        'operation_id' => $operation->id,
        'result_status' => $operation->result_status,
        'identical_retry_noop' => true,
        'automatic_reopen_rejected' => $reopenRejected,
    ];
}

function importantHistoryFingerprint(PDO $pdo): array
{
    $all = allTableFingerprint($pdo);
    $names = [
        'business_domains', 'business_domain_items', 'business_domain_item_attributes',
        'business_domain_revisions', 'business_domain_operations', 'company_financial_periods',
        'projects', 'project_members', 'tasks', 'improvements', 'ai_chat_threads',
        'project_local_connections',
    ];

    return array_intersect_key($all, array_flip($names));
}

function finalAssertions(int $newUserId, array $preflight, array $historyBefore): array
{
    $old = User::query()->findOrFail(AS_G03_OLD_USER_ID);
    $new = User::query()->findOrFail($newUserId);
    $orgKeep = Organization::query()->findOrFail(AS_G03_KEEP_ORGANIZATION_ID);
    $orgTarget = Organization::query()->findOrFail(AS_G03_TARGET_ORGANIZATION_ID);
    $oldKeepMembership = OrganizationUser::query()->findOrFail(AS_G03_KEEP_MEMBERSHIP_ID);
    $oldTargetMembership = OrganizationUser::query()->findOrFail(AS_G03_TARGET_MEMBERSHIP_ID);
    $newTargetMembership = OrganizationUser::query()
        ->where('organization_id', AS_G03_TARGET_ORGANIZATION_ID)->where('user_id', $newUserId)->sole();
    $workspace = Workspace::query()->findOrFail(AS_G03_STANDARD_WORKSPACE_ID);
    $newWorkspaceMembership = WorkspaceMember::query()
        ->where('workspace_id', AS_G03_STANDARD_WORKSPACE_ID)->where('user_id', $newUserId)->sole();
    $oldEligibility = ProductAccountEligibility::query()->where('user_id', $old->id)->sole();
    $newEligibility = ProductAccountEligibility::query()->where('user_id', $new->id)->sole();

    assertRehearsal($old->is_active && $old->is_system_admin, 'Old global account changed unexpectedly.');
    assertRehearsal(! $new->is_system_admin && $new->is_active && $new->email_verified_at !== null, 'New account state is invalid.');
    assertRehearsal($oldKeepMembership->membership_status === OrganizationUser::STATUS_ACTIVE, 'Keep membership changed.');
    assertRehearsal($oldTargetMembership->membership_status === OrganizationUser::STATUS_SUSPENDED, 'Old target membership was not suspended.');
    assertRehearsal($newTargetMembership->membership_status === OrganizationUser::STATUS_ACTIVE
        && $newTargetMembership->organization_role === OrganizationUser::ORGANIZATION_ROLE_OWNER
        && $newTargetMembership->role === OrganizationUser::ROLE_OWNER
        && $newTargetMembership->company_role === OrganizationUser::COMPANY_ROLE_OWNER, 'New target Owner matrix is incomplete.');
    assertRehearsal($newTargetMembership->permissions === $preflight['state']['target_membership']['permissions'], 'Approved explicit permissions changed.');
    assertRehearsal($workspace->owner_user_id === $newUserId && $newWorkspaceMembership->role === WorkspaceMember::ROLE_OWNER, 'Workspace ownership did not move.');
    assertRehearsal($oldEligibility->mode === ProductAccountEligibility::MODE_SINGLE
        && $oldEligibility->product_organization_id === AS_G03_KEEP_ORGANIZATION_ID, 'Old eligibility did not converge to Keep single.');
    assertRehearsal($newEligibility->mode === ProductAccountEligibility::MODE_SINGLE
        && $newEligibility->product_organization_id === AS_G03_TARGET_ORGANIZATION_ID, 'New eligibility did not converge to Target single.');
    assertRehearsal($oldEligibility->compatibilities()->orderBy('organization_user_id')->pluck('organization_user_id')->all() === [1, 5], 'Compatibility Evidence was rewritten.');
    assertRehearsal(OrganizationUser::query()->where('organization_id', AS_G03_TARGET_ORGANIZATION_ID)
        ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
        ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)->count() === 1, 'Target Owner count is not exactly one.');

    $organizationAccess = app(OrganizationAccess::class);
    assertRehearsal($organizationAccess->hasActiveMembership($old, $orgKeep), 'Old account lost Keep organization.');
    assertRehearsal(! $organizationAccess->hasActiveMembership($old, $orgTarget), 'Old account retained active Target organization access.');
    assertRehearsal($organizationAccess->hasActiveMembership($new, $orgTarget), 'New account lacks Target organization access.');
    assertRehearsal(! $organizationAccess->hasActiveMembership($new, $orgKeep), 'New account gained Keep organization access.');
    assertRehearsal($new->canAccessWorkspace(AS_G03_STANDARD_WORKSPACE_ID), 'New account cannot access Standard Workspace.');
    assertRehearsal(! $old->canAccessWorkspace(AS_G03_STANDARD_WORKSPACE_ID), 'Old account can still access Target Workspace.');
    assertRehearsal(app(BusinessDomainAccess::class)->canEdit($new, $orgTarget), 'New Owner cannot edit Business Domain.');
    assertRehearsal(! app(BusinessDomainAccess::class)->canEdit($old, $orgTarget), 'Old suspended membership can edit Business Domain.');

    $companyAccess = app(CompanyAccess::class);
    $permissionMatrix = [];
    foreach (OrganizationUser::permissionLabels() as $permission => $label) {
        $permissionMatrix[$permission] = [
            'new_target' => $companyAccess->allows($new, $orgTarget, $permission),
            'old_target' => $companyAccess->allows($old, $orgTarget, $permission),
        ];
        assertRehearsal($permissionMatrix[$permission]['new_target'], 'New Owner lacks approved effective permission '.$permission.'.');
        assertRehearsal(! $permissionMatrix[$permission]['old_target'], 'Old account retained target permission '.$permission.'.');
    }

    $historyAfter = importantHistoryFingerprint(sqlitePdo((string) DB::connection()->getDatabaseName()));
    assertRehearsal($historyBefore === $historyAfter, 'History or business data fingerprint changed.');
    assertRehearsal(Organization::query()->count() === $preflight['state']['counts']['organizations'], 'Organization count changed.');
    assertRehearsal(Workspace::query()->count() === $preflight['state']['counts']['workspaces'], 'Workspace count changed.');
    assertRehearsal(DB::table('projects')->count() === $preflight['state']['counts']['projects'], 'Project count changed.');

    return [
        'old' => ['user_id' => $old->id, 'eligibility' => 'single', 'organization_id' => AS_G03_KEEP_ORGANIZATION_ID],
        'new' => ['user_id' => $new->id, 'eligibility' => 'single', 'organization_id' => AS_G03_TARGET_ORGANIZATION_ID, 'system_admin' => false],
        'target_owner_count' => 1,
        'permission_matrix' => $permissionMatrix,
        'history_fingerprint_preserved' => true,
        'business_counts_preserved' => true,
    ];
}

try {
    $repositoryRoot = realpath(dirname(__DIR__, 2));
    $source = realpath($argv[1] ?? '');
    $expectedSource = realpath($repositoryRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
    if ($source === false || $expectedSource === false || $source !== $expectedSource) {
        failRehearsal('AS-G03 source guard rejected the normal local database path.', 64);
    }
    $temp = realpath(sys_get_temp_dir());
    if ($temp === false) {
        failRehearsal('Unable to resolve the operating-system temporary directory.', 64);
    }
    $runDirectory = $temp.DIRECTORY_SEPARATOR.'company-os-as-g03-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (! mkdir($runDirectory, 0700) || realpath(dirname($runDirectory)) !== $temp) {
        failRehearsal('Unable to create the guarded AS-G03 run directory.', 64);
    }

    $sourceHashBefore = hash_file('sha256', $source);
    $sourceSizeBefore = filesize($source);
    $sourceFingerprintBefore = allTableFingerprint(sqlitePdo($source));
    $backup = $runDirectory.DIRECTORY_SEPARATOR.'normal-local-consistent-backup.sqlite';
    copySnapshot($source, $backup);
    $sourceHashAfterBackup = hash_file('sha256', $source);
    assertRehearsal(hash_equals($sourceHashBefore, $sourceHashAfterBackup), 'Normal local DB changed while the snapshot was created.');

    $working = $runDirectory.DIRECTORY_SEPARATOR.'company-os-as-g03-working.sqlite';
    cloneSnapshot($backup, $working);
    bootClone($working);
    assertRehearsal(config('product_ux.organization_admission_enabled') === true, 'Admission is not enabled inside the clone.');
    $preflight = preflight();
    $historyBefore = importantHistoryFingerprint(sqlitePdo($working));
    $standardWorkspacePreparation = prepareApprovedStandardWorkspace();
    $onboarding = createAndAcceptNewOwner();
    $postAcceptanceFingerprint = allTableFingerprint(sqlitePdo($working));
    $payloadHash = cutoverPayloadHash($onboarding['user_id'], $preflight);

    $failureRolledBack = false;
    try {
        executeCutover($onboarding['user_id'], $preflight, $payloadHash, true);
    } catch (RuntimeException $exception) {
        $failureRolledBack = str_contains($exception->getMessage(), 'injected failure');
    }
    assertRehearsal($failureRolledBack, 'Injected failure did not reach the expected stop point.');
    assertRehearsal($postAcceptanceFingerprint === allTableFingerprint(sqlitePdo($working)), 'Injected failure left a partial cutover.');

    $firstCutover = executeCutover($onboarding['user_id'], $preflight, $payloadHash);
    $afterFirstCutoverFingerprint = allTableFingerprint(sqlitePdo($working));
    $retryCutover = executeCutover($onboarding['user_id'], $preflight, $payloadHash);
    assertRehearsal($retryCutover['already_applied'] === true, 'Identical cutover retry was not a NOOP.');
    assertRehearsal($afterFirstCutoverFingerprint === allTableFingerprint(sqlitePdo($working)), 'Identical cutover retry changed data.');
    assertRehearsal(OrganizationMembershipLifecycleOperation::query()
        ->where('request_id', AS_G03_CUTOVER_REQUEST_ID)->count() === 1, 'Cutover retry duplicated its lifecycle receipt.');

    $payloadMismatchRejected = false;
    try {
        executeCutover($onboarding['user_id'], $preflight, str_repeat('f', 64));
    } catch (ValidationException $exception) {
        $payloadMismatchRejected = array_key_exists('request_id', $exception->errors());
    }
    assertRehearsal($payloadMismatchRejected, 'Changed payload reused the cutover request ID.');
    assertRehearsal($afterFirstCutoverFingerprint === allTableFingerprint(sqlitePdo($working)), 'Changed-payload rejection changed data.');

    $acceptance = finalAssertions($onboarding['user_id'], $preflight, $historyBefore);
    $finalization = finalizeLeftAndRejectReopen($onboarding['user_id']);

    $compensationClone = $runDirectory.DIRECTORY_SEPARATOR.'company-os-as-g03-compensation.sqlite';
    cloneSnapshot($backup, $compensationClone);
    selectClone($compensationClone);
    $compensationPreflight = preflight();
    prepareApprovedStandardWorkspace();
    $compensationOnboarding = createAndAcceptNewOwner();
    $compensationHash = cutoverPayloadHash($compensationOnboarding['user_id'], $compensationPreflight);
    executeCutover($compensationOnboarding['user_id'], $compensationPreflight, $compensationHash);
    $compensation = compensateSuspendedCutover($compensationOnboarding['user_id'], $compensationPreflight, $compensationHash);
    $compensatedOld = OrganizationUser::query()->findOrFail(AS_G03_TARGET_MEMBERSHIP_ID);
    $compensatedNew = User::query()->findOrFail($compensationOnboarding['user_id']);
    $compensatedNewMembership = OrganizationUser::query()
        ->where('organization_id', AS_G03_TARGET_ORGANIZATION_ID)
        ->where('user_id', $compensatedNew->id)->sole();
    assertRehearsal($compensatedOld->membership_status === OrganizationUser::STATUS_ACTIVE, 'Compensation did not restore old active membership.');
    assertRehearsal(ProductAccountEligibility::query()->where('user_id', AS_G03_OLD_USER_ID)->sole()->mode === ProductAccountEligibility::MODE_LEGACY_MULTI, 'Compensation did not restore legacy_multi.');
    assertRehearsal(Workspace::query()->findOrFail(AS_G03_STANDARD_WORKSPACE_ID)->owner_user_id === AS_G03_OLD_USER_ID, 'Compensation did not restore Workspace owner.');
    assertRehearsal($compensatedNew->email_verified_at !== null && $compensatedNewMembership->membership_status === OrganizationUser::STATUS_ACTIVE, 'Compensation incorrectly removed the independently created account.');
    assertRehearsal(OrganizationInvitation::query()->findOrFail($compensationOnboarding['invitation_id'])->status === OrganizationInvitation::STATUS_ACCEPTED, 'Compensation resurrected or rewound the accepted invitation.');
    $compensation['credential_and_invitation_not_rewound'] = true;
    $compensation['old_membership_restored'] = true;
    $compensation['old_eligibility_restored'] = true;
    $sourceHashFinal = hash_file('sha256', $source);
    $sourceSizeFinal = filesize($source);
    $sourceFingerprintFinal = allTableFingerprint(sqlitePdo($source));
    assertRehearsal(hash_equals($sourceHashBefore, $sourceHashFinal), 'Normal local DB physical hash changed during rehearsal.');
    assertRehearsal($sourceSizeBefore === $sourceSizeFinal, 'Normal local DB size changed during rehearsal.');
    assertRehearsal($sourceFingerprintBefore === $sourceFingerprintFinal, 'Normal local DB logical fingerprint changed during rehearsal.');

    $restore = $runDirectory.DIRECTORY_SEPARATOR.'company-os-as-g03-restored.sqlite';
    cloneSnapshot($backup, $restore);
    assertRehearsal(hash_equals(hash_file('sha256', $backup), hash_file('sha256', $restore)), 'Backup restore byte comparison failed.');

    $output = [
        'case_id' => 'AS-G03-'.date('Ymd-His'),
        'result' => 'PASS',
        'timezone' => date_default_timezone_get(),
        'repository_head' => trim((string) shell_exec('git rev-parse HEAD')),
        'source' => [
            'path' => $source,
            'size' => $sourceSizeBefore,
            'sha256_before' => $sourceHashBefore,
            'sha256_after' => $sourceHashFinal,
            'logical_tables_checked' => count($sourceFingerprintBefore),
            'unchanged' => true,
        ],
        'backup' => [
            'path' => $backup,
            'size' => filesize($backup),
            'sha256' => hash_file('sha256', $backup),
            'created_at_jst' => now()->timezone('Asia/Tokyo')->format('Y-m-d H:i:s T'),
            'integrity' => 'ok',
            'restore_byte_identical' => true,
        ],
        'clone' => [
            'path' => $working,
            'admission_enabled' => true,
            'mail_transport' => 'fake',
            'queue' => 'sync',
        ],
        'preflight' => $preflight,
        'standard_workspace_preparation' => $standardWorkspacePreparation,
        'onboarding' => $onboarding,
        'cutover' => [
            'manifest_hash' => $payloadHash,
            'operation_id' => $firstCutover['operation_id'],
            'failure_injection_rolled_back' => $failureRolledBack,
            'identical_retry_noop' => $retryCutover['already_applied'],
            'changed_payload_rejected' => $payloadMismatchRejected,
            'receipt_count' => 1,
        ],
        'compensation' => $compensation,
        'finalization' => $finalization,
        'acceptance' => $acceptance,
        'temporary_artifacts_removed' => true,
    ];
    DB::disconnect('sqlite');
    removeGuardedRunDirectory($runDirectory);
    echo json_encode($output, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'AS-G03 rehearsal failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}
