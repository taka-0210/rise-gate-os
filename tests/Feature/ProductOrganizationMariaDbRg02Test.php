<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\OwnerOnboarding;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Organization\OwnerOnboardingLegal;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductOrganizationMariaDbRg02Test extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->guardRg02();
        config(['product_ux.organization_admission_enabled' => true]);
        config(['mail.default' => 'array', 'queue.default' => 'sync']);
        config(['session.default' => 'array', 'cache.default' => 'array']);
        config([
            'owner_onboarding.legal_documents_published' => true,
            'owner_onboarding.terms.version' => 'rg02-terms-v1',
            'owner_onboarding.terms.content_hash' => str_repeat('a', 64),
            'owner_onboarding.terms.url' => 'https://example.test/terms',
            'owner_onboarding.privacy.version' => 'rg02-privacy-v1',
            'owner_onboarding.privacy.content_hash' => str_repeat('b', 64),
            'owner_onboarding.privacy.url' => 'https://example.test/privacy',
        ]);
        Artisan::call('migrate:fresh', ['--database' => 'mariadb', '--force' => true]);
        DB::statement('SET SESSION time_zone = '.DB::getPdo()->quote('+09:00'));
    }

    public static function commonAdmissionCases(): array
    {
        return [
            'C01 S4 x S4' => ['RG02-C01', 's4', 's4'],
            'C02 S6 x S6' => ['RG02-C02', 's6', 's6'],
            'C03 S4 x S6' => ['RG02-C03', 's4', 's6'],
            'C04 S6 x S4' => ['RG02-C04', 's6', 's4'],
            'C05 SA x S4' => ['RG02-C05', 'sa', 's4'],
            'C06 SA x S6' => ['RG02-C06', 'sa', 's6'],
            'C07 SA x SA' => ['RG02-C07', 'sa', 'sa'],
        ];
    }

    #[DataProvider('commonAdmissionCases')]
    public function test_common_admission_converges_on_mariadb(string $caseId, string $first, string $second): void
    {
        $user = $this->user('common-'.strtolower($caseId).'@example.test');
        $organizations = [$this->organization('a-'.strtolower($caseId)), $this->organization('b-'.strtolower($caseId))];
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:'.$caseId);
        $outcomes = $this->finishPair($this->startPair(
            $this->admissionDefinition($first, 'A', $user, $organizations[0]),
            $this->admissionDefinition($second, 'B', $user, $organizations[1]),
        ));
        $this->assertSame(1, collect($outcomes)->where('status', 'success')->count(), json_encode($outcomes));
        $this->assertSame(1, collect($outcomes)->where('status', 'rejected')->count(), json_encode($outcomes));
        $eligibility = $user->productAccountEligibility()->firstOrFail();
        $this->assertSame(ProductAccountEligibility::MODE_SINGLE, $eligibility->mode);
        $this->assertSame(1, OrganizationUser::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('account_security_events')->where('user_id', $user->id)->where('event', 'account.product_organization.bound')->count());
        $this->assertSame(in_array('s6', [$first, $second], true) ? 3 : 2, Organization::query()->count());
        $this->record($caseId, ['outcomes' => $outcomes, 'bound_organization_id' => $eligibility->product_organization_id]);
    }

    public function test_RG02_C08_same_organization_retry_is_idempotent(): void
    {
        $user = $this->user('same@example.test');
        $organization = $this->organization('same');
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:C08');
        $outcomes = $this->finishPair($this->startPair(
            $this->admissionDefinition('s4', 'A', $user, $organization),
            $this->admissionDefinition('s4', 'B', $user, $organization),
        ));
        $this->assertSame(2, collect($outcomes)->where('status', 'success')->count(), json_encode($outcomes));
        $retry = $this->runWorker('admit-existing', $this->payload('retry') + [
            'user_id' => $user->id, 'organization_id' => $organization->id,
            'entry' => ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT,
        ]);
        $this->assertSame('success', $retry['status'], json_encode($retry));
        $this->assertSame(1, OrganizationUser::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('account_security_events')->where('user_id', $user->id)->where('event', 'account.product_organization.bound')->count());
        $this->record('RG02-C08', ['outcomes' => $outcomes, 'retry' => $retry]);
    }

    public function test_RG02_D01_profile_and_constraints_are_real(): void
    {
        $profile = (array) DB::selectOne('SELECT VERSION() version, @@tx_isolation isolation_level, @@sql_mode sql_mode, @@time_zone time_zone, @@innodb_lock_wait_timeout lock_wait_timeout, @@innodb_rollback_on_timeout rollback_on_timeout, @@character_set_database charset_name, @@collation_database collation_name');
        $this->assertStringStartsWith('10.11.', $profile['version']);
        $this->assertSame('REPEATABLE-READ', strtoupper((string) $profile['isolation_level']));
        $this->assertStringContainsString('STRICT', strtoupper((string) $profile['sql_mode']));
        $this->assertSame('utf8mb4', $profile['charset_name']);
        $this->assertSame('utf8mb4_unicode_ci', $profile['collation_name']);
        $engines = DB::table('information_schema.tables')->where('table_schema', DB::getDatabaseName())->where('table_type', 'BASE TABLE')->pluck('engine')->unique()->values()->all();
        $this->assertSame(['InnoDB'], $engines);
        $user = $this->user('emoji@example.test', 'RG02 四字 🚀');
        $organization = $this->organization('constraint');
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id, 'mode' => 'single', 'product_organization_id' => $organization->id,
            'classification_version' => 'rg02', 'classified_at' => now(), 'evidence_ref' => 'rg02:D01',
        ]);
        $this->assertSame('RG02 四字 🚀', $user->fresh()->name);
        $this->assertConstraintViolation(fn () => DB::table('product_account_eligibilities')->insert([
            'user_id' => $this->user('invalid@example.test')->id, 'mode' => 'single', 'product_organization_id' => null,
            'classification_version' => 'rg02', 'classified_at' => now(), 'evidence_ref' => 'invalid', 'created_at' => now(), 'updated_at' => now(),
        ]), [3819, 4025]);
        $this->assertConstraintViolation(fn () => ProductAccountEligibility::query()->where('user_id', $user->id)->update(['product_organization_id' => null]), [3819, 4025]);
        $this->assertConstraintViolation(fn () => ProductAccountEligibility::query()->create([
            'user_id' => $user->id, 'mode' => 'single', 'product_organization_id' => $organization->id,
            'classification_version' => 'rg02', 'classified_at' => now(), 'evidence_ref' => 'duplicate',
        ]), [1062]);
        $this->record('RG02-D01', ['profile' => $profile, 'engines' => $engines]);
    }

    public function test_RG02_D02_row_lock_wait_is_observable(): void
    {
        $user = $this->user('lock@example.test');
        $organization = $this->organization('lock');
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:D02');
        DB::beginTransaction();
        ProductAccountEligibility::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();
        $running = $this->startWorker('admit-existing', $this->payload('waiter') + [
            'user_id' => $user->id, 'organization_id' => $organization->id,
            'entry' => ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT,
        ]);
        touch($running['go']);
        usleep(700000);
        $this->assertTrue(proc_get_status($running['process'])['running']);
        DB::commit();
        $outcome = $this->finishWorker($running);
        $this->assertSame('success', $outcome['status'], json_encode($outcome));
        $this->assertGreaterThanOrEqual(250, $outcome['elapsed_ms']);
        $other = $this->user('other-lock@example.test');
        $otherOrganization = $this->organization('other-lock');
        app(ProductOrganizationAdmission::class)->registerUnstarted($other, 'rg02:D02-other');
        $unrelated = $this->runWorker('admit-existing', $this->payload('unrelated') + [
            'user_id' => $other->id, 'organization_id' => $otherOrganization->id,
            'entry' => ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT,
        ]);
        $this->assertSame('success', $unrelated['status']);
        $this->record('RG02-D02', ['waiter' => $outcome, 'unrelated' => $unrelated]);
    }

    public function test_RG02_D03_real_deadlock_recovers_with_bounded_retry(): void
    {
        $aOrg = $this->organization('deadlock-a');
        $bOrg = $this->organization('deadlock-b');
        $aUser = $this->user('deadlock-a@example.test');
        $bUser = $this->user('deadlock-b@example.test');
        app(ProductOrganizationAdmission::class)->registerUnstarted($aUser, 'rg02:D03-A');
        app(ProductOrganizationAdmission::class)->registerUnstarted($bUser, 'rg02:D03-B');
        $paths = $this->paths('deadlock');
        $a = $this->startWorker('deadlock-admission', $this->payload('A', $paths) + [
            'user_id' => $aUser->id, 'target_organization_id' => $aOrg->id,
            'first_lock_id' => $aOrg->id, 'second_lock_id' => $bOrg->id,
            'locked' => $paths['prefix'].'.a.locked', 'both_locked' => $paths['prefix'].'.both',
        ]);
        $b = $this->startWorker('deadlock-admission', $this->payload('B', $paths) + [
            'user_id' => $bUser->id, 'target_organization_id' => $bOrg->id,
            'first_lock_id' => $bOrg->id, 'second_lock_id' => $aOrg->id,
            'locked' => $paths['prefix'].'.b.locked', 'both_locked' => $paths['prefix'].'.both',
        ]);
        touch($paths['go']);
        $this->waitFiles([$paths['prefix'].'.a.locked', $paths['prefix'].'.b.locked']);
        touch($paths['prefix'].'.both');
        $outcomes = [$this->finishWorker($a), $this->finishWorker($b)];
        $this->assertSame(2, collect($outcomes)->where('status', 'success')->count(), json_encode($outcomes));
        $this->assertTrue(collect($outcomes)->contains(fn ($outcome) => ($outcome['diagnostics']['callback_attempts'] ?? 0) >= 2));
        $this->assertSame(2, DB::table('account_security_events')->where('event', 'account.product_organization.bound')->count());
        $this->record('RG02-D03', ['outcomes' => $outcomes]);
        $this->cleanupPaths($paths, [$a['ready'], $b['ready'], $paths['prefix'].'.a.locked', $paths['prefix'].'.b.locked', $paths['prefix'].'.both']);
    }

    public function test_RG02_D04_timeout_rolls_back_partial_dml_and_recovers(): void
    {
        $locked = $this->organization('timeout-lock');
        $target = $this->organization('timeout-target');
        $user = $this->user('timeout@example.test');
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:D04');
        DB::beginTransaction();
        Organization::query()->whereKey($locked->id)->lockForUpdate()->firstOrFail();
        $running = $this->startWorker('timeout-admission', $this->payload('timeout') + [
            'user_id' => $user->id, 'target_organization_id' => $target->id,
            'locked_organization_id' => $locked->id,
        ]);
        touch($running['go']);
        usleep(2500000);
        DB::commit();
        $outcome = $this->finishWorker($running);
        $this->assertSame('success', $outcome['status'], json_encode($outcome));
        $this->assertGreaterThanOrEqual(2, $outcome['diagnostics']['callback_attempts'] ?? 0);
        $this->assertSame(1, Organization::query()->where('slug', 'rg02-timeout-marker')->count());
        $this->assertSame(1, DB::table('account_security_events')->where('user_id', $user->id)->where('event', 'account.product_organization.bound')->count());
        $this->record('RG02-D04', ['outcome' => $outcome]);
    }

    public function test_RG02_I01_real_S4_and_S6_converge_in_both_orders(): void
    {
        $variants = [];
        foreach (['s4-first', 's6-first'] as $variant) {
            if ($variants !== []) {
                Artisan::call('migrate:fresh', ['--database' => 'mariadb', '--force' => true]);
            }
            [$user, $s4] = $this->s4Fixture($variant);
            $s6 = $this->s6Fixture($user, $variant);
            $firstS4 = $variant === 's4-first';
            $outcomes = $this->finishPair($this->startPair(
                ['mode' => 's4-accept', 'payload' => $this->payload('S4') + $s4 + ['pre_delay_us' => $firstS4 ? 0 : 150000]],
                ['mode' => 's6-complete', 'payload' => $this->payload('S6') + $s6 + ['pre_delay_us' => $firstS4 ? 150000 : 0]],
            ));
            $this->assertSame(1, collect($outcomes)->where('status', 'success')->count(), json_encode($outcomes));
            $this->assertSame(1, collect($outcomes)->where('status', 'rejected')->count(), json_encode($outcomes));
            $this->assertSame(1, DB::table('account_security_events')->where('user_id', $user->id)->where('event', 'account.product_organization.bound')->count());
            $this->assertSame(1, OrganizationUser::query()->where('user_id', $user->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE)->count());
            $variants[$variant] = $outcomes;
        }
        $this->record('RG02-I01', ['variants' => $variants]);
    }

    public function test_RG02_I02_real_SA_HTTP_and_S4_converge_in_both_orders(): void
    {
        $variants = [];
        foreach (['sa-first', 's4-first'] as $variant) {
            if ($variants !== []) {
                Artisan::call('migrate:fresh', ['--database' => 'mariadb', '--force' => true]);
            }
            [$user, $s4] = $this->s4Fixture($variant);
            $admin = $this->user('admin-'.$variant.'@example.test', 'Admin', true);
            $workspace = $this->workspace($admin, $this->organization('sa-'.$variant), 'sa-'.$variant);
            $firstSa = $variant === 'sa-first';
            $outcomes = $this->finishPair($this->startPair(
                ['mode' => 'sa-http', 'payload' => $this->payload('SA') + [
                    'admin_id' => $admin->id, 'user_id' => $user->id, 'workspace_id' => $workspace->id,
                    'pre_delay_us' => $firstSa ? 0 : 150000,
                ]],
                ['mode' => 's4-accept', 'payload' => $this->payload('S4') + $s4 + ['pre_delay_us' => $firstSa ? 150000 : 0]],
            ));
            $this->assertFalse(collect($outcomes)->contains(fn ($outcome) => in_array($outcome['status'], ['sql_error', 'harness_error'], true)), json_encode($outcomes));
            $this->assertSame(1, OrganizationUser::query()->where('user_id', $user->id)->where('membership_status', OrganizationUser::STATUS_ACTIVE)->count());
            $variants[$variant] = $outcomes;
        }
        $this->record('RG02-I02', ['variants' => $variants]);
    }

    public function test_RG02_I03_S6_is_idempotent_and_failpoint_recovers(): void
    {
        $user = $this->user('s6-same@example.test');
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:I03');
        $fixture = $this->s6Fixture($user, 'same');
        $outcomes = $this->finishPair($this->startPair(
            ['mode' => 's6-complete', 'payload' => $this->payload('A') + $fixture],
            ['mode' => 's6-complete', 'payload' => $this->payload('B') + $fixture],
        ));
        $this->assertSame(2, collect($outcomes)->where('status', 'success')->count(), json_encode($outcomes));
        $this->assertSame(1, Organization::query()->whereNotNull('standard_workspace_id')->count());
        $this->assertSame(1, DB::table('account_security_events')->where('user_id', $user->id)->where('event', 'account.product_organization.bound')->count());
        Artisan::call('migrate:fresh', ['--database' => 'mariadb', '--force' => true]);
        $retryUser = $this->user('s6-retry@example.test');
        app(ProductOrganizationAdmission::class)->registerUnstarted($retryUser, 'rg02:I03-retry');
        $retryFixture = $this->s6Fixture($retryUser, 'retry');
        $failed = $this->runWorker('s6-complete', $this->payload('fail') + $retryFixture + ['fail_after_step' => 'audit']);
        $this->assertSame('harness_error', $failed['status']);
        $this->assertSame(0, Organization::query()->count());
        $recovered = $this->runWorker('s6-complete', $this->payload('retry') + $retryFixture);
        $this->assertSame('success', $recovered['status'], json_encode($recovered));
        $this->assertSame(1, Organization::query()->count());
        $this->record('RG02-I03', ['concurrent' => $outcomes, 'failpoint' => $failed, 'retry' => $recovered]);
    }

    public function test_RG02_I04_same_SA_HTTP_add_converges_without_duplicate_error(): void
    {
        $admin = $this->user('sa-admin@example.test', 'Admin', true);
        $user = $this->user('sa-target@example.test');
        $organization = $this->organization('sa-same');
        $workspace = $this->workspace($admin, $organization, 'sa-same');
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:I04');
        $payload = ['admin_id' => $admin->id, 'user_id' => $user->id, 'workspace_id' => $workspace->id];
        $outcomes = $this->finishPair($this->startPair(
            ['mode' => 'sa-http', 'payload' => $this->payload('A') + $payload],
            ['mode' => 'sa-http', 'payload' => $this->payload('B') + $payload],
        ));
        $this->assertFalse(collect($outcomes)->contains(fn ($outcome) => in_array($outcome['status'], ['sql_error', 'harness_error'], true)), json_encode($outcomes));
        $this->assertSame(1, WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->count());
        $this->assertSame(WorkspaceMember::ROLE_MEMBER, WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->value('role'));
        $retry = $this->runWorker('sa-http', $this->payload('retry') + $payload);
        $this->assertNotSame('sql_error', $retry['status'], json_encode($retry));
        $this->record('RG02-I04', ['outcomes' => $outcomes, 'retry' => $retry]);
    }

    private function guardRg02(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('1', getenv('RG02_ALLOW'));
        $this->assertSame('127.0.0.1', (string) config('database.connections.mariadb.host'));
        $this->assertStringStartsWith('co_rg02_', (string) config('database.connections.mariadb.database'));
        $identity = DB::selectOne('SELECT VERSION() version, DATABASE() db, @@datadir datadir');
        $this->assertStringStartsWith('10.11.', (string) $identity->version);
        $this->assertSame((string) config('database.connections.mariadb.database'), (string) $identity->db);
        $this->assertSame(rtrim(str_replace('\\', '/', getenv('RG02_EXPECTED_DATADIR') ?: ''), '/'), rtrim(str_replace('\\', '/', (string) $identity->datadir), '/'));
    }

    private function user(string $email, string $name = 'RG02 User', bool $admin = false): User
    {
        $user = User::query()->create([
            'name' => $name, 'email' => $email, 'password' => Hash::make('rg02-not-used'),
            'is_active' => true, 'is_system_admin' => $admin,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => 'RG02 '.$slug, 'slug' => 'rg02-'.$slug]);
    }

    private function membership(User $user, Organization $organization, string $status = OrganizationUser::STATUS_ACTIVE, string $role = OrganizationUser::ORGANIZATION_ROLE_MEMBER): OrganizationUser
    {
        return OrganizationUser::query()->create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'role' => $role === OrganizationUser::ORGANIZATION_ROLE_OWNER ? OrganizationUser::ROLE_OWNER : OrganizationUser::ROLE_MEMBER,
            'organization_role' => $role, 'membership_status' => $status,
            'joined_at' => $status === OrganizationUser::STATUS_ACTIVE ? now() : null,
        ]);
    }

    private function workspace(User $owner, Organization $organization, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'organization_id' => $organization->id, 'owner_user_id' => $owner->id,
            'name' => 'RG02 '.$slug, 'slug' => 'rg02-'.$slug, 'status' => Workspace::STATUS_ACTIVE,
        ]);
        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id,
            'role' => WorkspaceMember::ROLE_OWNER, 'joined_at' => now(),
        ]);

        return $workspace;
    }

    private function s4Fixture(string $suffix): array
    {
        $sponsor = $this->user('sponsor-'.$suffix.'@example.test');
        $user = $this->user('invitee-'.$suffix.'@example.test');
        $organization = $this->organization('invite-'.$suffix);
        $this->membership($sponsor, $organization, OrganizationUser::STATUS_ACTIVE, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $workspace = $this->workspace($sponsor, $organization, 'invite-'.$suffix);
        $organization->update(['standard_workspace_id' => $workspace->id]);
        $this->membership($user, $organization, OrganizationUser::STATUS_INVITED);
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:S4-'.$suffix);
        $token = 'rg02-invitation-token-'.$suffix;
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id, 'created_by_user_id' => $sponsor->id,
            'sponsor_user_id' => $sponsor->id, 'normalized_email' => strtolower($user->email),
            'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'status' => OrganizationInvitation::STATUS_PENDING, 'pending_email_key' => strtolower($user->email),
            'token_hash' => hash('sha256', $token), 'token_generation' => 1, 'expires_at' => now()->addDay(),
        ]);

        return [$user, ['user_id' => $user->id, 'invitation_id' => $invitation->id, 'token' => $token]];
    }

    private function s6Fixture(User $user, string $suffix): array
    {
        $admin = $this->user('issuer-'.$suffix.'@example.test', 'Issuer', true);
        if (! $user->productAccountEligibility()->exists()) {
            app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'rg02:S6-'.$suffix);
        }
        $token = 'rg02-owner-token-'.$suffix;
        $onboarding = OwnerOnboarding::query()->create([
            'issued_by_user_id' => $admin->id, 'normalized_email' => strtolower($user->email),
            'organization_name' => 'RG02 Owner '.$suffix, 'normalized_organization_name' => 'rg02 owner '.$suffix,
            'pending_case_key' => hash('sha256', 'rg02-'.$suffix), 'duplicate_decision' => 'no_match',
            'status' => OwnerOnboarding::STATUS_ISSUED, 'token_hash' => hash('sha256', $token),
            'token_generation' => 1, 'expires_at' => now()->addDay(), 'claimed_user_id' => $user->id,
        ]);
        app(OwnerOnboardingLegal::class)->record($user, $onboarding);

        return ['user_id' => $user->id, 'onboarding_id' => $onboarding->id, 'token' => $token];
    }

    private function assertConstraintViolation(callable $operation, array $codes): void
    {
        try {
            $operation();
            $this->fail('Expected MariaDB constraint violation.');
        } catch (QueryException $exception) {
            $this->assertContains((int) ($exception->errorInfo[1] ?? 0), $codes);
        }
    }

    private function admissionDefinition(string $entry, string $worker, User $user, Organization $organization): array
    {
        return [
            'mode' => $entry === 's6' ? 'admit-new' : 'admit-existing',
            'payload' => $this->payload($worker) + [
                'user_id' => $user->id, 'organization_id' => $organization->id,
                'entry' => match ($entry) {
                    's4' => ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT,
                    's6' => ProductOrganizationAdmission::ENTRY_OWNER_COMPLETE,
                    default => ProductOrganizationAdmission::ENTRY_SYSTEM_ADMIN_WORKSPACE,
                },
            ],
        ];
    }

    private function paths(string $label): array
    {
        $prefix = sys_get_temp_dir().DIRECTORY_SEPARATOR.'company-os-rg02-'.$label.'-'.bin2hex(random_bytes(5));

        return ['prefix' => $prefix, 'go' => $prefix.'.go'];
    }

    private function payload(string $worker, ?array $paths = null): array
    {
        $paths ??= $this->paths(strtolower($worker));

        return ['worker' => $worker, 'ready' => $paths['prefix'].'.'.$worker.'.ready', 'go' => $paths['go']];
    }

    private function startPair(array $first, array $second): array
    {
        $paths = $this->paths('pair');
        $first['payload']['go'] = $paths['go'];
        $second['payload']['go'] = $paths['go'];
        $first['payload']['ready'] = $paths['prefix'].'.a.ready';
        $second['payload']['ready'] = $paths['prefix'].'.b.ready';
        $a = $this->startWorker($first['mode'], $first['payload']);
        $b = $this->startWorker($second['mode'], $second['payload']);
        $this->waitFiles([$a['ready'], $b['ready']]);
        touch($paths['go']);

        return ['a' => $a, 'b' => $b, 'paths' => $paths];
    }

    private function finishPair(array $pair): array
    {
        try {
            return [$this->finishWorker($pair['a']), $this->finishWorker($pair['b'])];
        } finally {
            $this->cleanupPaths($pair['paths'], [$pair['a']['ready'], $pair['b']['ready']]);
        }
    }

    private function runWorker(string $mode, array $payload): array
    {
        $running = $this->startWorker($mode, $payload);
        touch($running['go']);

        return $this->finishWorker($running);
    }

    private function startWorker(string $mode, array $payload): array
    {
        $command = [PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Support/product_organization_mariadb_rg02_worker.php'), $mode, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR))];
        $pipes = [];
        $environment = getenv();
        $environment['DB_USERNAME'] = (string) getenv('RG02_WORKER_USER');
        $environment['DB_PASSWORD'] = (string) getenv('RG02_WORKER_PASSWORD');
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        $this->assertIsResource($process);

        return ['process' => $process, 'pipes' => $pipes, 'ready' => $payload['ready'], 'go' => $payload['go']];
    }

    private function finishWorker(array $running): array
    {
        $stdout = stream_get_contents($running['pipes'][1]);
        $stderr = stream_get_contents($running['pipes'][2]);
        fclose($running['pipes'][1]);
        fclose($running['pipes'][2]);
        $exit = proc_close($running['process']);
        $decoded = json_decode(trim($stdout), true);
        $this->assertIsArray($decoded, 'Worker invalid JSON exit '.$exit.': '.$stderr.' / '.$stdout);
        $decoded['exit'] = $exit;
        $decoded['stderr'] = $decoded['status'] === 'harness_error' ? $stderr : '';

        return $decoded;
    }

    private function waitFiles(array $paths): void
    {
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if (collect($paths)->every(fn ($path) => is_file($path))) {
                return;
            }
            usleep(20000);
        }
        $this->fail('RG02 workers did not reach the barrier.');
    }

    private function cleanupPaths(array $paths, array $extra = []): void
    {
        foreach (array_merge([$paths['go']], $extra) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function record(string $caseId, array $evidence): void
    {
        $path = getenv('RG02_EVIDENCE_FILE') ?: '';
        $safeRoot = str_replace('\\', '/', sys_get_temp_dir()).'/company-os-rg02-';
        if ($path === '' || ! str_starts_with(str_replace('\\', '/', $path), $safeRoot)) {
            $this->fail('RG02 evidence path guard rejected the target.');
        }
        file_put_contents($path, json_encode([
            'case_id' => $caseId, 'status' => 'PASS',
            'recorded_at_jst' => now('Asia/Tokyo')->toIso8601String(), 'evidence' => $evidence,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
