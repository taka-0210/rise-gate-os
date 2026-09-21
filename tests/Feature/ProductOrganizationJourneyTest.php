<?php

namespace Tests\Feature;

use App\Mail\AccountActionMail;
use App\Models\Client;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ProductOrganization\ProductOrganizationAdmission;
use App\Services\ProductOrganization\ProductOrganizationInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductOrganizationJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'product_ux.organization_admission_enabled' => true,
            'owner_onboarding.legal_documents_published' => true,
            'owner_onboarding.terms.version' => 'pux-a-terms',
            'owner_onboarding.terms.content_hash' => hash('sha256', 'pux-a-terms'),
            'owner_onboarding.terms.url' => 'https://example.test/terms',
            'owner_onboarding.privacy.version' => 'pux-a-privacy',
            'owner_onboarding.privacy.content_hash' => hash('sha256', 'pux-a-privacy'),
            'owner_onboarding.privacy.url' => 'https://example.test/privacy',
        ]);
        Mail::fake();
    }

    public function test_bootstrap_creates_one_atomic_single_binding(): void
    {
        $this->post(route('register'), [
            'name' => 'Bootstrap Owner',
            'email' => 'bootstrap@example.test',
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
            'organization_name' => 'Bootstrap Company',
            'workspace_name' => 'Main',
        ])->assertRedirect(route('company.home'));

        $user = User::query()->firstOrFail();
        $this->assertDatabaseHas('product_account_eligibilities', [
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => Organization::query()->sole()->id,
        ]);
    }

    public function test_single_login_enters_own_company_and_cannot_use_switch_endpoint(): void
    {
        [$user, $organization] = $this->singleUser('single-login');
        $other = $this->organization('other');

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $organization->id);
        $this->post(route('companies.switch', $organization))->assertForbidden();
        $this->post(route('companies.switch', $other))->assertForbidden();
    }

    public function test_valid_onboarding_claim_keeps_priority_over_product_state_during_login(): void
    {
        $sponsor = User::factory()->create();
        $user = User::factory()->create([
            'email' => 'claim-priority@example.test',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'claim-priority');
        $organization = $this->organization('claim-priority-company');
        $this->membership($sponsor, $organization, OrganizationUser::STATUS_ACTIVE, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$invitation, $token] = $this->invitation($sponsor, $organization, $user);

        $this->get(route('invitations.claim', [
            'invitation' => $invitation->public_id,
            'token' => $token,
        ]))->assertRedirect(route('invitations.onboarding'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('invitations.onboarding'));
        $this->assertSame(ProductAccountEligibility::MODE_UNSTARTED, $user->productAccountEligibility()->firstOrFail()->mode);
    }

    public function test_owner_onboarding_binds_first_company_and_completed_retry_is_idempotent(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($admin)->withSession([
            'access_mode' => 'system_admin',
            'credential_generation' => $admin->credential_generation,
        ])->post(route('system-admin.owner-onboardings.store'), [
            'email' => 'pux-owner@example.test',
            'organization_name' => 'PUX Owner Company',
            'duplicate_decision' => 'no_match',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $url = Mail::sent(AccountActionMail::class)->last()->actionUrl;
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('owner-onboarding.show'));
        $this->post(route('owner-onboarding.register'), [
            'name' => 'PUX Owner',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accept_terms' => '1',
            'accept_privacy' => '1',
        ])->assertRedirect(route('owner-onboarding.show'));
        $user = User::query()->where('email', 'pux-owner@example.test')->firstOrFail();
        $this->assertSame(ProductAccountEligibility::MODE_UNSTARTED, $user->productAccountEligibility()->firstOrFail()->mode);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh());

        config(['owner_onboarding.fail_after_step' => 'workspace']);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertStatus(500);
        config(['owner_onboarding.fail_after_step' => null]);
        $this->assertDatabaseMissing('organizations', ['name' => 'PUX Owner Company']);
        $this->assertSame(
            ProductAccountEligibility::MODE_UNSTARTED,
            $user->productAccountEligibility()->firstOrFail()->mode,
        );
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertRedirect(route('company.home'));
        $eligibility = $user->productAccountEligibility()->firstOrFail();
        $this->assertSame(ProductAccountEligibility::MODE_SINGLE, $eligibility->mode);
        $this->post(route('owner-onboarding.complete'), ['confirm_owner_responsibility' => '1'])
            ->assertRedirect(route('company.home'));
        $this->assertDatabaseCount('organizations', 1);
        $this->assertDatabaseCount('product_account_eligibilities', 1);
    }

    public function test_owner_prepare_and_complete_reject_a_different_second_company(): void
    {
        [$user, $first] = $this->singleUser('owner-first');
        $admin = User::factory()->create(['is_system_admin' => true]);
        $beforeOrganizations = Organization::query()->count();

        $this->actingAs($admin)->withSession([
            'access_mode' => 'system_admin',
            'credential_generation' => $admin->credential_generation,
        ])->post(route('system-admin.owner-onboardings.store'), [
            'email' => $user->email,
            'organization_name' => 'Owner Second',
            'duplicate_decision' => 'distinct_company',
            'distinct_company_reason' => 'PUX-A second-company rejection evidence.',
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $url = Mail::sent(AccountActionMail::class)->last()->actionUrl;

        $this->actingAs($user)->withSession($this->credentialSession($user))
            ->get($url)
            ->assertRedirect(route('owner-onboarding.show'));
        $this->post(route('owner-onboarding.prepare'), [
            'accept_terms' => '1',
            'accept_privacy' => '1',
        ])->assertSessionHasErrors('product_organization');
        $this->post(route('owner-onboarding.complete'), [
            'confirm_owner_responsibility' => '1',
        ])->assertSessionHasErrors('product_organization');

        $this->assertSame($beforeOrganizations, Organization::query()->count());
        $this->assertDatabaseMissing('organizations', ['name' => 'Owner Second']);
        $this->assertSame($first->id, $user->productAccountEligibility()->firstOrFail()->product_organization_id);
    }

    public function test_legacy_multi_requires_explicit_selection_and_stopped_company_does_not_kill_active_company(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $active = $this->organization('legacy-active');
        $stopped = $this->organization('legacy-stopped');
        $this->membership($user, $active);
        $this->membership($user, $stopped, OrganizationUser::STATUS_SUSPENDED);
        app(ProductOrganizationInventory::class)->apply($user, 'legacy-login');

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('companies.index'))
            ->assertSessionMissing('current_company_id');
        $this->get(route('companies.index'))->assertOk()->assertSee($active->name)->assertDontSee($stopped->name);
        $this->post(route('companies.switch', $active))
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $active->id);
        $this->post(route('companies.switch', $stopped))->assertForbidden();
    }

    public function test_system_admin_exit_uses_the_same_product_organization_resolution(): void
    {
        [$admin, $organization] = $this->singleUser('admin-exit');
        $admin->update(['is_system_admin' => true]);
        $this->actingAs($admin)->withSession([
            'access_mode' => 'system_admin',
            'credential_generation' => $admin->credential_generation,
        ])->post(route('system-admin.exit'))
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $organization->id);

        $unstarted = User::factory()->create(['is_system_admin' => true]);
        app(ProductOrganizationAdmission::class)->registerUnstarted($unstarted, 'admin-exit-unstarted');
        $this->actingAs($unstarted)->withSession([
            'access_mode' => 'system_admin',
            'credential_generation' => $unstarted->credential_generation,
        ])->post(route('system-admin.exit'))
            ->assertRedirect(route('companies.index'))
            ->assertSessionMissing('current_company_id');
    }

    public function test_unstarted_suspended_left_and_missing_eligibility_have_safe_account_pages_without_loop(): void
    {
        $unstarted = User::factory()->create();
        app(ProductOrganizationAdmission::class)->registerUnstarted($unstarted, 'new');
        $this->actingAs($unstarted)->withSession($this->credentialSession($unstarted))
            ->get(route('companies.index'))->assertOk()->assertSee('Owner Onboarding');

        foreach ([OrganizationUser::STATUS_SUSPENDED => '一時停止', OrganizationUser::STATUS_LEFT => '所属は終了'] as $status => $copy) {
            [$user] = $this->singleUser('state-'.$status, $status);
            $this->actingAs($user)->withSession($this->credentialSession($user))
                ->get(route('companies.index'))->assertOk()->assertSee($copy);
            $this->get(route('account.profile'))->assertOk();
        }

        $missing = User::factory()->create();
        $this->actingAs($missing)->withSession($this->credentialSession($missing))
            ->get(route('companies.index'))->assertOk()->assertSee('確認が必要');
    }

    public function test_review_and_missing_eligibility_preserve_only_existing_active_memberships(): void
    {
        $missing = User::factory()->create(['password' => Hash::make('password')]);
        $missingOrganization = $this->organization('missing-existing-active');
        $this->membership($missing, $missingOrganization);

        $this->post(route('login'), ['email' => $missing->email, 'password' => 'password'])
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $missingOrganization->id);
        $this->assertDatabaseMissing('product_account_eligibilities', ['user_id' => $missing->id]);
        $this->post(route('logout'));

        $review = User::factory()->create(['password' => Hash::make('password')]);
        $activeA = $this->organization('review-active-a');
        $activeB = $this->organization('review-active-b');
        $invited = $this->organization('review-invited');
        $suspended = $this->organization('review-suspended');
        $left = $this->organization('review-left');
        $this->membership($review, $activeA);
        $this->membership($review, $activeB);
        $this->membership($review, $invited, OrganizationUser::STATUS_INVITED);
        $this->membership($review, $suspended, OrganizationUser::STATUS_SUSPENDED);
        $this->membership($review, $left, OrganizationUser::STATUS_LEFT);
        ProductAccountEligibility::query()->create([
            'user_id' => $review->id,
            'mode' => ProductAccountEligibility::MODE_REVIEW_REQUIRED,
            'classification_version' => config('product_ux.classification_version'),
            'classified_at' => now(),
            'evidence_ref' => 'test:approved-pux-c02-adapter',
        ]);

        $this->post(route('login'), ['email' => $review->email, 'password' => 'password'])
            ->assertRedirect(route('companies.index'))
            ->assertSessionMissing('current_company_id');
        $this->get(route('companies.index'))
            ->assertOk()
            ->assertSee($activeA->name)
            ->assertSee($activeB->name)
            ->assertDontSee($invited->name)
            ->assertDontSee($suspended->name)
            ->assertDontSee($left->name);
        $this->post(route('companies.switch', $activeA))
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $activeA->id);
        $this->post(route('companies.switch', $suspended))->assertForbidden();

        $newOrganization = $this->organization('review-new-organization');
        try {
            app(ProductOrganizationAdmission::class)->admitExistingOrganization(
                $review,
                $newOrganization,
                ProductOrganizationAdmission::ENTRY_STAFF_ACCEPT,
                fn (): string => 'must-not-run',
            );
            $this->fail('review_required admission created a new organization membership.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('organization_users', [
                'organization_id' => $newOrganization->id,
                'user_id' => $review->id,
            ]);
        }
    }

    public function test_staff_prepare_does_not_bind_but_accept_binds_membership_workspace_and_audit_atomically(): void
    {
        $sponsor = User::factory()->create();
        $user = User::factory()->create(['email' => 'invitee@example.test', 'email_verified_at' => now()]);
        $organization = $this->organization('invited-company');
        $this->membership($sponsor, $organization, OrganizationUser::STATUS_ACTIVE, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $workspace = $this->workspace($sponsor, $organization, 'standard');
        $organization->update(['standard_workspace_id' => $workspace->id]);
        app(ProductOrganizationAdmission::class)->registerUnstarted($user, 'staff-provenance');
        [$invitation, $token] = $this->invitation($sponsor, $organization, $user);

        $this->actingAs($user)->withSession($this->credentialSession($user))
            ->get(route('invitations.claim', ['invitation' => $invitation->public_id, 'token' => $token]))
            ->assertRedirect(route('invitations.onboarding'));
        $this->post(route('invitations.prepare'))->assertRedirect();
        $this->assertSame(ProductAccountEligibility::MODE_UNSTARTED, $user->productAccountEligibility()->firstOrFail()->mode);
        $this->post(route('invitations.accept'))->assertRedirect(route('company.home'));

        $this->assertDatabaseHas('product_account_eligibilities', [
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
        ]);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $user->id,
            'event' => 'account.product_organization.bound',
            'outcome' => 'success',
        ]);
    }

    public function test_staff_prepare_for_second_company_is_rejected_without_creating_membership(): void
    {
        [$user] = $this->singleUser('first-company');
        $sponsor = User::factory()->create();
        $second = $this->organization('second-company');
        $this->membership($sponsor, $second, OrganizationUser::STATUS_ACTIVE, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $workspace = $this->workspace($sponsor, $second, 'second-standard');
        $second->update(['standard_workspace_id' => $workspace->id]);
        [$invitation, $token] = $this->invitation($sponsor, $second, $user);

        $this->actingAs($user)->withSession($this->credentialSession($user))
            ->get(route('invitations.claim', ['invitation' => $invitation->public_id, 'token' => $token]));
        $this->post(route('invitations.prepare'))->assertSessionHasErrors('product_organization');
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $second->id, 'user_id' => $user->id]);
    }

    public function test_staff_same_company_retry_is_noop_and_prepare_is_rechecked_after_another_binding(): void
    {
        [$user, $organization] = $this->singleUser('staff-same');
        $membership = OrganizationUser::query()
            ->where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->firstOrFail();
        $sponsor = User::factory()->create();
        $this->membership($sponsor, $organization, OrganizationUser::STATUS_ACTIVE, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $workspace = $this->workspace($sponsor, $organization, 'staff-same-standard');
        $organization->update(['standard_workspace_id' => $workspace->id]);
        [$invitation, $token] = $this->invitation($sponsor, $organization, $user);

        $this->actingAs($user)->withSession($this->credentialSession($user))
            ->get(route('invitations.claim', ['invitation' => $invitation->public_id, 'token' => $token]));
        $this->post(route('invitations.prepare'))->assertRedirect();
        $this->post(route('invitations.accept'))->assertRedirect(route('company.home'));
        $this->post(route('invitations.accept'))->assertRedirect(route('company.home'));

        $this->assertSame(
            $membership->id,
            OrganizationUser::query()
                ->where('user_id', $user->id)
                ->where('organization_id', $organization->id)
                ->sole()
                ->id,
        );
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_MEMBER, $membership->fresh()->organization_role);
        $this->assertSame($organization->id, $user->productAccountEligibility()->firstOrFail()->product_organization_id);

        $newUser = User::factory()->create(['email_verified_at' => now()]);
        app(ProductOrganizationAdmission::class)->registerUnstarted($newUser, 'staff-prepare-race');
        [$pending, $pendingToken] = $this->invitation($sponsor, $organization, $newUser);
        $this->actingAs($newUser)->withSession($this->credentialSession($newUser))
            ->get(route('invitations.claim', ['invitation' => $pending->public_id, 'token' => $pendingToken]));
        $this->post(route('invitations.prepare'))->assertRedirect();

        $other = $this->organization('staff-race-winner');
        app(ProductOrganizationAdmission::class)->admitExistingOrganization(
            $newUser,
            $other,
            ProductOrganizationAdmission::ENTRY_SYSTEM_ADMIN_WORKSPACE,
            fn () => $this->membership($newUser, $other),
        );
        $this->post(route('invitations.accept'))->assertSessionHasErrors('product_organization');
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $newUser->id,
            'membership_status' => OrganizationUser::STATUS_INVITED,
        ]);
        $this->assertNotSame('accepted', $pending->fresh()->status);
    }

    public function test_system_admin_can_bind_first_workspace_but_not_add_a_second_organization(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $target = User::factory()->create();
        app(ProductOrganizationAdmission::class)->registerUnstarted($target, 'admin-target');
        $first = $this->organization('admin-first');
        $second = $this->organization('admin-second');
        $firstWorkspace = $this->workspace($admin, $first, 'admin-first-ws');
        $secondWorkspace = $this->workspace($admin, $second, 'admin-second-ws');
        $session = ['access_mode' => 'system_admin', 'credential_generation' => $admin->credential_generation];

        $this->actingAs($admin)->withSession($session)->post(route('system-admin.members.workspaces.store', $target), [
            'workspace_id' => $firstWorkspace->id,
            'workspace_role' => WorkspaceMember::ROLE_MEMBER,
        ])->assertRedirect();
        $this->assertSame($first->id, $target->productAccountEligibility()->firstOrFail()->product_organization_id);

        $this->post(route('system-admin.members.workspaces.store', $target), [
            'workspace_id' => $secondWorkspace->id,
            'workspace_role' => WorkspaceMember::ROLE_MEMBER,
        ])->assertSessionHasErrors('product_organization');
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $second->id, 'user_id' => $target->id]);
    }

    public function test_system_admin_cannot_force_inactive_or_non_active_memberships_into_workspace_access(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $session = ['access_mode' => 'system_admin', 'credential_generation' => $admin->credential_generation];

        $inactive = User::factory()->create(['is_active' => false]);
        app(ProductOrganizationAdmission::class)->registerUnstarted($inactive, 'inactive-admin-target');
        $inactiveOrganization = $this->organization('inactive-admin-target');
        $inactiveWorkspace = $this->workspace($admin, $inactiveOrganization, 'inactive-admin-workspace');
        $this->actingAs($admin)->withSession($session)
            ->post(route('system-admin.members.workspaces.store', $inactive), [
                'workspace_id' => $inactiveWorkspace->id,
                'workspace_role' => WorkspaceMember::ROLE_MEMBER,
            ])->assertSessionHasErrors('workspace_id');
        $this->assertDatabaseMissing('organization_users', [
            'organization_id' => $inactiveOrganization->id,
            'user_id' => $inactive->id,
        ]);
        $this->assertSame(
            ProductAccountEligibility::MODE_UNSTARTED,
            $inactive->productAccountEligibility()->firstOrFail()->mode,
        );

        foreach ([
            OrganizationUser::STATUS_INVITED,
            OrganizationUser::STATUS_SUSPENDED,
            OrganizationUser::STATUS_LEFT,
        ] as $status) {
            [$target, $organization] = $this->singleUser('admin-status-'.$status, $status);
            $workspace = $this->workspace($admin, $organization, 'admin-status-workspace-'.$status);
            $this->actingAs($admin)->withSession($session)
                ->post(route('system-admin.members.workspaces.store', $target), [
                    'workspace_id' => $workspace->id,
                    'workspace_role' => WorkspaceMember::ROLE_MEMBER,
                ])->assertSessionHasErrors('workspace_id');
            $this->assertDatabaseMissing('workspace_members', [
                'workspace_id' => $workspace->id,
                'user_id' => $target->id,
            ]);
            $this->assertDatabaseHas('organization_users', [
                'organization_id' => $organization->id,
                'user_id' => $target->id,
                'membership_status' => $status,
            ]);
        }
    }

    public function test_client_company_promotion_ui_http_and_direct_service_are_retired_without_touching_existing_link(): void
    {
        [$owner, $organization] = $this->singleUser('provider');
        $workspace = $this->workspace($owner, $organization, 'provider-ws');
        $client = Client::query()->create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'name' => 'Client',
        ]);
        $session = $this->credentialSession($owner) + [
            'current_company_id' => $organization->id,
            'current_company_access_epoch' => 1,
            'current_workspace_id' => $workspace->id,
            'access_mode' => 'workspace',
        ];

        $this->actingAs($owner)->withSession($session)
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertDontSee(route('clients.company-account.store', $client));
        $this->post(route('clients.company-account.store', $client), ['workspace_name' => 'New'])
            ->assertSessionHasErrors('product_organization');
        $this->assertNull($client->fresh()->linked_organization_id);
    }

    private function singleUser(string $slug, string $status = OrganizationUser::STATUS_ACTIVE): array
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $organization = $this->organization($slug);
        $this->membership($user, $organization, $status);
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
            'classification_version' => config('product_ux.classification_version'),
            'classified_at' => now(),
            'evidence_ref' => 'test:'.$slug,
        ]);

        return [$user, $organization];
    }

    private function organization(string $slug): Organization
    {
        return Organization::query()->create(['name' => $slug, 'slug' => $slug]);
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
            'name' => $slug, 'slug' => $slug, 'status' => Workspace::STATUS_ACTIVE,
        ]);
        WorkspaceMember::query()->firstOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $owner->id],
            ['role' => WorkspaceMember::ROLE_OWNER, 'joined_at' => now()],
        );

        return $workspace;
    }

    private function invitation(User $sponsor, Organization $organization, User $target): array
    {
        $token = 'safe-invitation-token';
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $organization->id, 'created_by_user_id' => $sponsor->id,
            'sponsor_user_id' => $sponsor->id, 'normalized_email' => strtolower($target->email),
            'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'pending_email_key' => strtolower($target->email),
            'token_hash' => hash('sha256', $token), 'token_generation' => 1,
            'expires_at' => now()->addDay(),
        ]);

        return [$invitation, $token];
    }

    private function credentialSession(User $user): array
    {
        return ['access_mode' => 'workspace', 'credential_generation' => $user->credential_generation];
    }
}
