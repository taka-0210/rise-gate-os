<?php

namespace Tests\Feature;

use App\Jobs\SendOwnerOnboardingMail;
use App\Mail\AccountActionMail;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\OwnerOnboarding;
use App\Models\OwnerOnboardingAuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Company\CompanyAccess;
use App\Services\Organization\OrganizationSessionContext;
use App\Services\Organization\OwnerOnboardingMailer;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OwnerOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'owner_onboarding.legal_documents_published' => true,
            'owner_onboarding.terms.version' => 'terms-fixture-v1',
            'owner_onboarding.terms.content_hash' => hash('sha256', 'terms-fixture-v1'),
            'owner_onboarding.terms.url' => 'https://example.test/terms',
            'owner_onboarding.privacy.version' => 'privacy-fixture-v1',
            'owner_onboarding.privacy.content_hash' => hash('sha256', 'privacy-fixture-v1'),
            'owner_onboarding.privacy.url' => 'https://example.test/privacy',
            'owner_onboarding.resend_cooldown_seconds' => 60,
        ]);
        Mail::fake();
    }

    public function test_scope_six_schema_is_additive_and_existing_organizations_keep_personal_creation(): void
    {
        $this->assertTrue(Schema::hasColumn('organizations', 'personal_workspace_creation_enabled'));
        $this->assertTrue(Schema::hasTable('owner_onboardings'));
        $this->assertTrue(Schema::hasTable('owner_onboarding_operations'));
        $this->assertTrue(Schema::hasTable('owner_onboarding_audit_events'));
        $this->assertTrue(Schema::hasTable('user_legal_consents'));

        $legacy = Organization::query()->create(['name' => 'Legacy', 'slug' => 'legacy']);
        $this->assertTrue($legacy->fresh()->personal_workspace_creation_enabled);
    }

    public function test_only_active_system_admin_can_issue_and_no_company_is_created_until_owner_completes(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true, 'is_active' => true]);
        $inactive = User::factory()->create(['is_system_admin' => true, 'is_active' => false]);
        $regular = User::factory()->create();
        $before = Organization::query()->count();

        $this->asSystemAdmin($regular)->post(route('system-admin.owner-onboardings.store'), $this->issuePayload())
            ->assertForbidden();
        $this->asSystemAdmin($inactive)->post(route('system-admin.owner-onboardings.store'), $this->issuePayload())
            ->assertRedirect();

        [$onboarding, $url] = $this->issue($admin, 'owner@example.test', 'New Company');
        $this->assertSame($before, Organization::query()->count());
        $this->assertNull($onboarding->completed_organization_id);
        $this->assertDatabaseMissing('organization_users', ['user_id' => $admin->id]);
        $this->assertStringNotContainsString($onboarding->token_hash, $url);
        $this->assertDatabaseHas('owner_onboarding_audit_events', ['event' => 'owner_onboarding.issued']);
    }

    public function test_new_owner_creates_account_then_atomically_starts_minimal_company(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        [$onboarding, $url] = $this->issue($admin, 'new-owner@example.test', '株式会社 新会社');

        $this->get($url)->assertRedirect(route('owner-onboarding.show'));
        $this->get(route('owner-onboarding.show'))->assertOk()->assertSee('Accountを作成');
        $this->post(route('owner-onboarding.register'), [
            'name' => 'New Owner',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => '1',
            'accept_privacy' => '1',
        ])->assertRedirect(route('owner-onboarding.show'));

        $user = User::query()->where('email', 'new-owner@example.test')->firstOrFail();
        $this->assertFalse($user->is_system_admin);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertSame(0, Organization::query()->where('name', '株式会社 新会社')->count());
        $this->assertDatabaseHas('user_legal_consents', [
            'user_id' => $user->id,
            'owner_onboarding_id' => $onboarding->id,
            'recorded_timezone' => 'Asia/Tokyo',
        ]);

        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertSessionHasErrors('email');
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh());
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertRedirect(route('company.home'));

        $onboarding->refresh();
        $organization = $onboarding->completedOrganization;
        $this->assertSame(OwnerOnboarding::STATUS_COMPLETED, $onboarding->status);
        $this->assertFalse($organization->personal_workspace_creation_enabled);
        $membership = OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_OWNER, $membership->organization_role);
        $this->assertSame(OrganizationUser::ROLE_MEMBER, $membership->role);
        $this->assertSame(OrganizationUser::COMPANY_ROLE_MEMBER, $membership->company_role);
        $this->assertSame([], $membership->permissions);
        $workspace = $organization->standardWorkspace;
        $this->assertSame(Workspace::TYPE_SHARED, $workspace->type);
        $this->assertSame(Workspace::STATUS_ACTIVE, $workspace->status);
        $this->assertSame(Workspace::BILLING_INCLUDED, $workspace->billing_type);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMember::ROLE_OWNER,
        ]);
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $organization->id, 'user_id' => $admin->id]);
        $this->assertFalse(app(CompanyAccess::class)->allows($user, $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_PL));
        $this->get(route('company.home'))->assertOk()->assertSee('Staffを迎える')->assertSee('Staff Invitationへ');
    }

    public function test_existing_single_user_cannot_start_second_company_and_keeps_identity_and_membership(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $user = User::factory()->create(['email' => 'existing@example.test', 'password' => 'original-password']);
        $first = Organization::query()->create(['name' => 'First', 'slug' => 'first']);
        $firstMembership = OrganizationUser::query()->create([
            'organization_id' => $first->id, 'user_id' => $user->id, 'role' => 'member',
            'organization_role' => 'member', 'membership_status' => 'active',
            'company_role' => 'accounting', 'permissions' => [OrganizationUser::PERMISSION_FINANCE_VIEW_PL], 'joined_at' => now(),
        ]);
        $this->establishSingleProductOrganization($user, $first);
        $password = $user->password;
        $organizationCount = Organization::query()->count();
        [$onboarding, $url] = $this->issue($admin, $user->email, 'Second');

        $this->get($url)->assertRedirect(route('owner-onboarding.show'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'original-password'])
            ->assertRedirect(route('owner-onboarding.show'));
        $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1'])
            ->assertSessionHasErrors('product_organization');
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertSessionHasErrors('product_organization');

        $this->assertSame($password, $user->fresh()->password);
        $this->assertSame('accounting', $firstMembership->fresh()->company_role);
        $this->assertSame([OrganizationUser::PERMISSION_FINANCE_VIEW_PL], $firstMembership->fresh()->permissions);
        $this->assertSame(1, $user->organizations()->count());
        $this->assertSame($organizationCount, Organization::query()->count());
        $this->assertDatabaseMissing('organizations', ['name' => 'Second']);
        $this->assertSame(OwnerOnboarding::STATUS_ISSUED, $onboarding->fresh()->status);
        $this->assertNull($onboarding->fresh()->claimed_user_id);
    }

    public function test_completion_is_idempotent_and_cannot_revive_suspended_membership(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $user = User::factory()->create(['email' => 'retry@example.test']);
        $this->establishUnstartedProductAccount($user);
        [$onboarding, $url] = $this->issue($admin, $user->email, 'Retry Company');
        $this->get($url);
        $this->actingAs($user)->withSession(['access_mode' => 'workspace', 'credential_generation' => $user->credential_generation]);
        $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1']);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertRedirect(route('company.home'));
        $organizationId = $onboarding->fresh()->completed_organization_id;

        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertRedirect(route('company.home'));
        $this->assertSame(1, Organization::query()->whereKey($organizationId)->count());
        $this->assertSame(1, Organization::query()->where('name', 'Retry Company')->count());
        $this->assertSame(1, OwnerOnboardingAuditEvent::query()->where('event', 'owner_onboarding.completed')->count());

        OrganizationUser::query()->where('organization_id', $organizationId)->where('user_id', $user->id)
            ->update(['membership_status' => OrganizationUser::STATUS_SUSPENDED, 'access_epoch' => 2]);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertSessionHasErrors('onboarding');
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organizationId,
            'user_id' => $user->id,
            'membership_status' => OrganizationUser::STATUS_SUSPENDED,
        ]);
    }

    public function test_resend_revoke_and_issuer_disable_invalidate_old_or_unapproved_completion(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $user = User::factory()->create(['email' => 'lifecycle@example.test']);
        $this->establishUnstartedProductAccount($user);
        [$onboarding, $oldUrl] = $this->issue($admin, $user->email, 'Lifecycle');
        $this->travel(61)->seconds();
        $this->asSystemAdmin($admin)->post(route('system-admin.owner-onboardings.resend', $onboarding), ['request_id' => (string) Str::uuid()])->assertRedirect();
        $newUrl = $this->latestUrl();
        $this->post(route('logout'));
        $this->get($oldUrl)->assertSessionHasErrors('onboarding');
        $this->get($newUrl)->assertRedirect(route('owner-onboarding.show'));
        $this->actingAs($user)->withSession(['access_mode' => 'workspace', 'credential_generation' => $user->credential_generation]);
        $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1']);
        $admin->update(['is_active' => false]);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertSessionHasErrors('onboarding');

        $admin->update(['is_active' => true]);
        $this->asSystemAdmin($admin)->delete(route('system-admin.owner-onboardings.revoke', $onboarding), ['request_id' => (string) Str::uuid()])->assertRedirect();
        $this->get($newUrl)->assertSessionHasErrors('onboarding');
        $this->assertSame(0, Organization::query()->where('name', 'Lifecycle')->count());
    }

    public function test_current_legal_version_is_rechecked_and_unpublished_customer_entry_is_closed(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $user = User::factory()->create(['email' => 'legal@example.test']);
        $this->establishUnstartedProductAccount($user);
        [$onboarding, $url] = $this->issue($admin, $user->email, 'Legal');
        $this->get($url);
        $this->actingAs($user)->withSession(['access_mode' => 'workspace', 'credential_generation' => $user->credential_generation]);
        $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1']);

        config([
            'owner_onboarding.terms.version' => 'terms-fixture-v2',
            'owner_onboarding.terms.content_hash' => hash('sha256', 'terms-fixture-v2'),
        ]);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertSessionHasErrors('consent');
        $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1'])->assertRedirect();
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertRedirect(route('company.home'));

        config(['owner_onboarding.legal_documents_published' => false]);
        $other = OwnerOnboarding::query()->create([
            'issued_by_user_id' => $admin->id, 'normalized_email' => 'closed@example.test',
            'organization_name' => 'Closed', 'normalized_organization_name' => 'closed',
            'token_hash' => hash('sha256', 'closed-token'), 'expires_at' => now()->addDay(),
        ]);
        $this->get(route('owner-onboarding.claim', ['onboarding' => $other->public_id, 'token' => 'closed-token']))->assertStatus(503);
    }

    public function test_atomic_failpoints_leave_no_partial_company_and_new_personal_workspace_is_denied(): void
    {
        foreach (['organization', 'membership', 'workspace', 'result', 'audit'] as $step) {
            $admin = User::factory()->create(['is_system_admin' => true]);
            $user = User::factory()->create(['email' => $step.'@example.test']);
            $this->establishUnstartedProductAccount($user);
            [$onboarding, $url] = $this->issue($admin, $user->email, 'Failure '.$step);
            $this->get($url);
            $this->actingAs($user)->withSession(['access_mode' => 'workspace', 'credential_generation' => $user->credential_generation]);
            $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1']);
            config(['owner_onboarding.fail_after_step' => $step]);
            $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertStatus(500);
            config(['owner_onboarding.fail_after_step' => null]);
            $this->assertSame(0, Organization::query()->where('name', 'Failure '.$step)->count());
            $this->assertSame(OwnerOnboarding::STATUS_ISSUED, $onboarding->fresh()->status);
        }

        $admin = User::factory()->create(['is_system_admin' => true]);
        $user = User::factory()->create(['email' => 'personal-off@example.test']);
        $this->establishUnstartedProductAccount($user);
        [$onboarding, $url] = $this->issue($admin, $user->email, 'Personal Off');
        $this->get($url);
        $this->actingAs($user)->withSession(['access_mode' => 'workspace', 'credential_generation' => $user->credential_generation]);
        $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1']);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1']);
        $organization = $onboarding->fresh()->completedOrganization;
        $this->withSession(['access_mode' => 'workspace', 'current_company_id' => $organization->id, 'current_company_access_epoch' => 1, 'credential_generation' => $user->credential_generation])
            ->get(route('workspaces.create'))->assertOk()->assertDontSee('個人Workspace</option>', false);
        $this->post(route('workspaces.store'), ['workspace_name' => 'Private', 'type' => 'personal'])->assertForbidden();
    }

    public function test_new_account_survives_company_failure_and_completed_company_survives_session_failure(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        [$onboarding, $url] = $this->issue($admin, 'resume-owner@example.test', 'Resume Company');
        $this->get($url);
        $this->post(route('owner-onboarding.register'), [
            'name' => 'Resume Owner',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => '1',
            'accept_privacy' => '1',
        ])->assertRedirect(route('owner-onboarding.show'));
        $owner = User::query()->where('email', 'resume-owner@example.test')->firstOrFail();
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($owner->fresh());

        config(['owner_onboarding.fail_after_step' => 'workspace']);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertStatus(500);
        config(['owner_onboarding.fail_after_step' => null]);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => 'resume-owner@example.test']);
        $this->assertDatabaseMissing('organizations', ['name' => 'Resume Company']);
        $this->assertSame(OwnerOnboarding::STATUS_ISSUED, $onboarding->fresh()->status);

        $sessionContext = Mockery::mock(OrganizationSessionContext::class);
        $sessionContext->shouldReceive('select')->once()->andThrow(new RuntimeException('session unavailable'));
        $this->app->instance(OrganizationSessionContext::class, $sessionContext);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertStatus(500);
        $organizationId = $onboarding->fresh()->completed_organization_id;
        $this->assertNotNull($organizationId);
        $this->assertDatabaseHas('organizations', ['id' => $organizationId, 'name' => 'Resume Company']);

        $this->app->forgetInstance(OrganizationSessionContext::class);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertRedirect(route('company.home'));
        $this->assertSame($organizationId, $onboarding->fresh()->completed_organization_id);
        $this->assertSame(1, Organization::query()->where('name', 'Resume Company')->count());
        $this->assertSame(1, OwnerOnboardingAuditEvent::query()->where('event', 'owner_onboarding.completed')->count());
    }

    public function test_same_name_requires_admin_reason_and_mail_job_is_encrypted_after_commit(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        Organization::query()->create(['name' => 'Same Name', 'slug' => 'same-name']);
        $this->asSystemAdmin($admin)->post(route('system-admin.owner-onboardings.store'), $this->issuePayload('same@example.test', 'Same Name'))
            ->assertSessionHasErrors('duplicate_decision');
        $payload = $this->issuePayload('same@example.test', 'Same Name');
        $payload['duplicate_decision'] = 'distinct_company';
        $payload['distinct_company_reason'] = '法人番号が異なる別法人であることを確認済み';
        $this->asSystemAdmin($admin)->post(route('system-admin.owner-onboardings.store'), $payload)->assertRedirect();
        $this->assertDatabaseHas('owner_onboardings', ['duplicate_decision' => 'distinct_company']);

        $interfaces = class_implements(SendOwnerOnboardingMail::class);
        $this->assertContains(ShouldBeEncrypted::class, $interfaces);
        $this->assertContains(ShouldQueueAfterCommit::class, $interfaces);
        $this->assertDatabaseMissing('owner_onboarding_audit_events', ['metadata' => $this->latestUrl()]);
    }

    public function test_mail_enqueue_failure_keeps_the_case_and_allows_immediate_recovery(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $mailer = Mockery::mock(OwnerOnboardingMailer::class);
        $mailer->shouldReceive('assertConfigured')->once();
        $mailer->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        $this->app->instance(OwnerOnboardingMailer::class, $mailer);

        $this->asSystemAdmin($admin)
            ->post(route('system-admin.owner-onboardings.store'), $this->issuePayload('delivery@example.test', 'Delivery Recovery'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Mail投入に失敗'));

        $onboarding = OwnerOnboarding::query()->firstOrFail();
        $this->assertSame(OwnerOnboarding::DELIVERY_FAILED, $onboarding->delivery_status);
        $this->assertNotNull($onboarding->delivery_failed_at);
        $this->assertDatabaseHas('owner_onboarding_audit_events', [
            'owner_onboarding_id' => $onboarding->id,
            'event' => 'owner_onboarding.mail_enqueue_failed',
            'outcome' => 'failed',
        ]);
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_issue_request_is_idempotent_and_owner_token_is_not_a_staff_invitation_token(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $payload = $this->issuePayload('purpose@example.test', 'Purpose Bound');
        $this->asSystemAdmin($admin)->post(route('system-admin.owner-onboardings.store'), $payload)->assertRedirect();
        $onboarding = OwnerOnboarding::query()->firstOrFail();
        $url = $this->latestUrl();
        $this->asSystemAdmin($admin)->post(route('system-admin.owner-onboardings.store'), $payload)->assertRedirect();
        $this->assertDatabaseCount('owner_onboardings', 1);
        $this->assertDatabaseCount('owner_onboarding_operations', 1);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->post(route('logout'));
        $this->get(route('invitations.claim', [
            'invitation' => $onboarding->public_id,
            'token' => $query['token'],
        ]))->assertSessionHasErrors('invitation');
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_expiry_wrong_account_and_changed_email_are_rechecked_at_use_time(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $target = User::factory()->create(['email' => 'target@example.test']);
        $this->establishUnstartedProductAccount($target);
        $wrong = User::factory()->create(['email' => 'wrong@example.test']);
        [$onboarding, $url] = $this->issue($admin, $target->email, 'Identity Check');

        $this->get($url);
        $this->actingAs($wrong)->withSession(['access_mode' => 'workspace', 'credential_generation' => $wrong->credential_generation]);
        $this->get(route('owner-onboarding.show'))->assertForbidden();

        $this->post(route('logout'));
        $this->get($url);
        $this->actingAs($target)->withSession(['access_mode' => 'workspace', 'credential_generation' => $target->credential_generation]);
        $this->post(route('owner-onboarding.prepare'), ['accept_terms' => '1', 'accept_privacy' => '1'])->assertRedirect();
        $target->forceFill(['email' => 'changed@example.test', 'email_verified_at' => now()])->save();
        $this->actingAs($target->fresh());
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])->assertForbidden();
        $this->assertNull($onboarding->fresh()->completed_organization_id);

        $expired = OwnerOnboarding::query()->create([
            'issued_by_user_id' => $admin->id,
            'normalized_email' => 'expired@example.test',
            'organization_name' => 'Expired',
            'normalized_organization_name' => 'expired',
            'pending_case_key' => hash('sha256', 'expired'),
            'token_hash' => hash('sha256', 'expired-token'),
            'expires_at' => now(),
        ]);
        $this->post(route('logout'));
        $this->get(route('owner-onboarding.claim', [
            'onboarding' => $expired->public_id,
            'token' => 'expired-token',
        ]))->assertSessionHasErrors('onboarding');
    }

    private function issue(User $admin, string $email, string $organizationName): array
    {
        $this->asSystemAdmin($admin)->post(route('system-admin.owner-onboardings.store'), $this->issuePayload($email, $organizationName))->assertRedirect();
        $this->post(route('logout'));

        return [OwnerOnboarding::query()->latest('id')->firstOrFail(), $this->latestUrl()];
    }

    private function issuePayload(string $email = 'owner@example.test', string $organizationName = 'New Company'): array
    {
        return [
            'email' => $email,
            'organization_name' => $organizationName,
            'duplicate_decision' => 'no_match',
            'request_id' => (string) Str::uuid(),
        ];
    }

    private function asSystemAdmin(User $user): static
    {
        return $this->actingAs($user)->withSession([
            'access_mode' => 'system_admin',
            'credential_generation' => (int) $user->credential_generation,
        ]);
    }

    private function latestUrl(): string
    {
        $mail = Mail::sent(AccountActionMail::class)->last();
        $this->assertNotNull($mail);

        return $mail->actionUrl;
    }
}
