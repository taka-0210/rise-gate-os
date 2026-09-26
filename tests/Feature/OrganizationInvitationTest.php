<?php

namespace Tests\Feature;

use App\Jobs\SendOrganizationInvitationMail;
use App\Mail\AccountActionMail;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Organization\OrganizationAdministration;
use App\Services\Organization\OrganizationInvitationMailer;
use App\Services\Organization\OrganizationInvitationMembershipWriter;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_four_schema_is_additive_constrained_and_allows_only_one_pending_email_per_org(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'avatar_path', 'avatar_mime', 'avatar_width', 'avatar_height', 'avatar_updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('organizations', 'standard_workspace_id'));
        $this->assertTrue(Schema::hasTable('organization_invitations'));
        $this->assertTrue(Schema::hasTable('organization_invitation_groups'));
        $this->assertTrue(Schema::hasTable('organization_invitation_operations'));

        $organization = $this->organization('schema');
        $first = OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'normalized_email' => 'unique@example.test',
            'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'pending_email_key' => 'unique@example.test',
            'token_hash' => hash('sha256', 'first'),
            'expires_at' => now()->addDay(),
        ]);
        try {
            OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'normalized_email' => 'unique@example.test',
                'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
                'pending_email_key' => 'unique@example.test',
                'token_hash' => hash('sha256', 'second'),
                'expires_at' => now()->addDay(),
            ]);
            $this->fail('The pending invitation uniqueness constraint was not enforced.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $first->forceFill(['status' => OrganizationInvitation::STATUS_REVOKED, 'pending_email_key' => null])->save();
        OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'normalized_email' => 'unique@example.test',
            'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'pending_email_key' => 'unique@example.test',
            'token_hash' => hash('sha256', 'third'),
            'expires_at' => now()->addDay(),
        ]);
        $this->assertDatabaseCount('organization_invitations', 2);
    }

    public function test_owner_admin_role_matrix_group_boundaries_and_secret_storage(): void
    {
        Mail::fake();
        $organization = $this->organization('matrix');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        [$member] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Sales']);

        $privileged = $this->issue($owner, $organization, 'new-owner@example.test', OrganizationUser::ORGANIZATION_ROLE_OWNER, [$group->id]);
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_OWNER, $privileged->intended_organization_role);
        $this->assertDatabaseHas('organization_invitation_groups', [
            'organization_invitation_id' => $privileged->id,
            'organization_group_id' => $group->id,
        ]);
        $url = $this->latestInvitationUrl();
        $this->assertStringNotContainsString($privileged->token_hash, $url);
        $this->assertDatabaseMissing('organization_audit_events', ['metadata' => $url]);

        $adminInvitation = $this->issue($admin, $organization, 'member@example.test', null);
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_MEMBER, $adminInvitation->intended_organization_role);

        $this->asCompany($admin, $organization)->post(route('organization-management.invitations.store'), [
            'email' => 'forged@example.test',
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'request_id' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->asCompany($member, $organization)->post(route('organization-management.invitations.store'), [
            'email' => 'blocked@example.test',
            'request_id' => (string) Str::uuid(),
        ])->assertForbidden();

        $other = $this->organization('other');
        $otherGroup = OrganizationGroup::create(['organization_id' => $other->id, 'name' => 'Other']);
        $this->asCompany($owner, $organization)->post(route('organization-management.invitations.store'), [
            'email' => 'cross@example.test',
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'group_ids' => [$otherGroup->id],
            'request_id' => (string) Str::uuid(),
        ])->assertSessionHasErrors('group_ids');

        $this->travel(61)->seconds();
        $this->asCompany($admin, $organization)->post(route('organization-management.invitations.resend', $privileged), [
            'request_id' => (string) Str::uuid(),
        ])->assertForbidden();
        $this->asCompany($admin, $organization)->delete(route('organization-management.invitations.revoke', $privileged), [
            'request_id' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    public function test_standard_workspace_is_explicit_owner_only_empty_and_idempotent(): void
    {
        $organization = $this->organization('workspace');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        $existing = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Existing Data Workspace',
            'slug' => 'existing-'.strtolower((string) Str::ulid()),
            'status' => Workspace::STATUS_ACTIVE,
            'type' => Workspace::TYPE_SHARED,
        ]);

        $this->asCompany($admin, $organization)
            ->post(route('organization-management.standard-workspace.store'))
            ->assertForbidden();
        $this->asCompany($owner, $organization)
            ->post(route('organization-management.standard-workspace.store'))
            ->assertRedirect();

        $standard = $organization->fresh()->standardWorkspace;
        $this->assertNotNull($standard);
        $this->assertNotSame($existing->id, $standard->id);
        $this->assertSame(0, $standard->projects()->count());
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $standard->id,
            'user_id' => $owner->id,
            'role' => WorkspaceMember::ROLE_OWNER,
        ]);
        $before = Workspace::query()->count();
        $this->asCompany($owner, $organization)
            ->post(route('organization-management.standard-workspace.store'))
            ->assertRedirect();
        $this->assertSame($before, Workspace::query()->count());
        $this->assertSame($standard->id, $organization->fresh()->standard_workspace_id);
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $standard->id, 'user_id' => $admin->id]);
    }

    public function test_resend_invalidates_old_generation_get_does_not_consume_and_revoke_is_immediate(): void
    {
        Mail::fake();
        $organization = $this->organization('lifecycle');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $invitation = $this->issue($owner, $organization, 'lifecycle@example.test');
        $oldUrl = $this->latestInvitationUrl();

        $this->post(route('logout'));
        $this->get($oldUrl)->assertRedirect(route('invitations.onboarding'));
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->fresh()->status);

        $this->travel(61)->seconds();
        $this->asCompany($owner, $organization)->post(route('organization-management.invitations.resend', $invitation), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $newUrl = $this->latestInvitationUrl();
        $this->assertNotSame($oldUrl, $newUrl);
        $this->assertSame(2, $invitation->fresh()->token_generation);
        $this->post(route('logout'));
        $this->get($oldUrl)->assertSessionHasErrors('invitation');
        $this->get($newUrl)->assertRedirect(route('invitations.onboarding'));

        $this->asCompany($owner, $organization)->delete(route('organization-management.invitations.revoke', $invitation), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame(OrganizationInvitation::STATUS_REVOKED, $invitation->fresh()->status);
        $this->post(route('logout'));
        $this->get($newUrl)->assertSessionHasErrors('invitation');
    }

    public function test_new_user_journey_requires_verification_and_accepts_atomically_with_idempotent_retry(): void
    {
        Mail::fake();
        Storage::fake('local');
        $organization = $this->organization('new-user');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $groupA = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Sales']);
        $groupB = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Delivery']);
        $this->asCompany($owner, $organization)->post(route('organization-management.standard-workspace.store'))->assertRedirect();
        $standard = $organization->fresh()->standardWorkspace;
        $invitation = $this->issue($owner, $organization, 'new-user@example.test', OrganizationUser::ORGANIZATION_ROLE_MEMBER, [$groupA->id, $groupB->id]);
        $url = $this->latestInvitationUrl();

        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('invitations.onboarding'));
        $this->get(route('invitations.onboarding'))->assertOk()->assertSee('Accountを作成');
        $this->post(route('invitations.register'), [
            'name' => 'Invited User',
            'password' => 'safe-password-123',
            'password_confirmation' => 'safe-password-123',
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'is_system_admin' => 1,
        ])->assertRedirect(route('invitations.onboarding'));

        $user = User::query()->where('email', 'new-user@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->is_system_admin);
        $membership = OrganizationUser::query()->where('user_id', $user->id)->where('organization_id', $organization->id)->firstOrFail();
        $this->assertSame(OrganizationUser::STATUS_INVITED, $membership->membership_status);
        $this->assertNull($membership->organization_role);
        $this->assertDatabaseCount('organization_group_memberships', 0);
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $standard->id, 'user_id' => $user->id]);

        $this->post(route('invitations.accept'))->assertSessionHasErrors('email');
        $this->assertSame(OrganizationUser::STATUS_INVITED, $membership->fresh()->membership_status);
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh());
        $this->post(route('invitations.accept'))->assertRedirect(route('company.home'));

        $membership->refresh();
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $membership->membership_status);
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_MEMBER, $membership->organization_role);
        $this->assertSame('member', $membership->role);
        $this->assertSame([], $membership->permissions);
        $this->assertDatabaseCount('organization_group_memberships', 2);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $standard->id,
            'user_id' => $user->id,
            'role' => WorkspaceMember::ROLE_MEMBER,
        ]);
        $this->assertSame(OrganizationInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertDatabaseHas('organization_audit_events', [
            'organization_id' => $organization->id,
            'subject_user_id' => $user->id,
            'event' => 'organization.invitation.accepted',
            'outcome' => OrganizationAuditEvent::OUTCOME_SUCCESS,
        ]);

        $this->post(route('invitations.accept'))->assertRedirect(route('company.home'));
        $this->assertDatabaseCount('organization_group_memberships', 2);
        $this->assertSame(1, OrganizationAuditEvent::query()->where('event', 'organization.invitation.accepted')->count());
    }

    public function test_existing_single_user_is_rejected_from_a_second_company_without_mutating_identity_or_membership(): void
    {
        Mail::fake();
        $organization = $this->organization('existing');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->asCompany($owner, $organization)->post(route('organization-management.standard-workspace.store'))->assertRedirect();
        $user = User::factory()->create(['name' => 'Existing Name', 'email' => 'existing@example.test']);
        $other = $this->organization('other-membership');
        $this->member($other, OrganizationUser::ORGANIZATION_ROLE_MEMBER, $user);
        $this->establishSingleProductOrganization($user, $other);
        $passwordBefore = $user->password;

        $invitation = $this->issue($owner, $organization, $user->email);
        $url = $this->latestInvitationUrl();
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('invitations.onboarding'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('invitations.onboarding'));
        $this->post(route('invitations.prepare'))->assertSessionHasErrors('product_organization');
        $this->post(route('invitations.accept'))->assertSessionHasErrors('product_organization');

        $this->assertSame('Existing Name', $user->fresh()->name);
        $this->assertSame($passwordBefore, $user->fresh()->password);
        $this->assertDatabaseHas('organization_users', ['organization_id' => $other->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $organization->id, 'user_id' => $user->id]);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->fresh()->status);
    }

    public function test_request_idempotency_encrypted_queue_and_pending_owner_do_not_weaken_last_owner_guard(): void
    {
        Queue::fake();
        $organization = $this->organization('idempotency');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $requestId = (string) Str::uuid();
        $payload = [
            'email' => 'pending-owner@example.test',
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'request_id' => $requestId,
        ];

        $this->asCompany($owner, $organization)
            ->post(route('organization-management.invitations.store'), $payload)
            ->assertRedirect();
        $this->asCompany($owner, $organization)
            ->post(route('organization-management.invitations.store'), $payload)
            ->assertRedirect();
        $this->assertDatabaseCount('organization_invitations', 1);
        $this->assertDatabaseCount('organization_invitation_operations', 1);
        Queue::assertPushed(SendOrganizationInvitationMail::class, 1);
        Queue::assertPushed(SendOrganizationInvitationMail::class, function ($job): bool {
            return $job instanceof ShouldBeEncrypted
                && $job instanceof ShouldQueueAfterCommit;
        });

        $this->asCompany($owner, $organization)->put(
            route('organization-management.memberships.role', $ownerMembership),
            ['organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER],
        )->assertSessionHasErrors('organization_role');
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_OWNER, $ownerMembership->fresh()->organization_role);

        $this->asCompany($owner, $organization)->post(route('organization-management.invitations.store'), [
            'email' => 'different@example.test',
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'request_id' => $requestId,
        ])->assertSessionHasErrors('request_id');
        $this->assertDatabaseCount('organization_invitations', 1);
    }

    public function test_acceptance_rolls_back_membership_group_workspace_token_and_audit_when_writer_fails(): void
    {
        Mail::fake();
        $organization = $this->organization('rollback');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Atomic']);
        $this->asCompany($owner, $organization)->post(route('organization-management.standard-workspace.store'))->assertRedirect();
        $workspace = $organization->fresh()->standardWorkspace;
        $user = User::factory()->create(['email' => 'atomic@example.test']);
        $this->establishUnstartedProductAccount($user);
        $invitation = $this->issue($owner, $organization, $user->email, OrganizationUser::ORGANIZATION_ROLE_MEMBER, [$group->id]);
        $url = $this->latestInvitationUrl();
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('invitations.onboarding'));
        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('invitations.onboarding'));
        $this->post(route('invitations.prepare'))->assertRedirect();

        $this->mock(OrganizationInvitationMembershipWriter::class, function ($mock): void {
            $mock->shouldReceive('activate')->once()->andThrow(new RuntimeException('fault injection'));
        });
        $this->withoutExceptionHandling();
        try {
            $this->post(route('invitations.accept'));
            $this->fail('The injected writer failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fault injection', $exception->getMessage());
        }

        $membership = OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(OrganizationUser::STATUS_INVITED, $membership->membership_status);
        $this->assertNull($membership->organization_role);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->fresh()->status);
        $this->assertDatabaseMissing('organization_group_memberships', ['organization_user_id' => $membership->id]);
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('organization_audit_events', ['event' => 'organization.invitation.accepted']);
    }

    public function test_pending_invitation_guards_group_archive_until_revoke(): void
    {
        Mail::fake();
        $organization = $this->organization('group-guard');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Planned']);
        $invitation = $this->issue($owner, $organization, 'planned@example.test', OrganizationUser::ORGANIZATION_ROLE_MEMBER, [$group->id]);

        $this->asCompany($owner, $organization)
            ->delete(route('organization-management.groups.archive', $group))
            ->assertSessionHasErrors('group');
        $this->assertNull($group->fresh()->archived_at);

        $this->asCompany($owner, $organization)->delete(route('organization-management.invitations.revoke', $invitation), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->asCompany($owner, $organization)
            ->delete(route('organization-management.groups.archive', $group))
            ->assertRedirect();
        $this->assertNotNull($group->fresh()->archived_at);
    }

    public function test_avatar_is_reencoded_private_and_visible_only_with_existing_identity_access(): void
    {
        Storage::fake('local');
        $organization = $this->organization('avatar');
        [$subject] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        [$colleague] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $outsider = User::factory()->create();

        $this->actingAs($subject)->withSession(['credential_generation' => 1, 'access_mode' => 'workspace'])
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->image('avatar.jpg', 900, 600),
            ])->assertRedirect();
        $subject->refresh();
        $this->assertSame('image/webp', $subject->avatar_mime);
        $this->assertSame(512, $subject->avatar_width);
        $this->assertSame(341, $subject->avatar_height);
        $this->assertStringEndsWith('.webp', $subject->avatar_path);
        Storage::disk('local')->assertExists($subject->avatar_path);

        $this->actingAs($colleague)->withSession(['credential_generation' => 1, 'access_mode' => 'workspace'])
            ->get(route('users.avatar', $subject))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $this->actingAs($outsider)->withSession(['credential_generation' => 1, 'access_mode' => 'workspace'])
            ->get(route('users.avatar', $subject))
            ->assertNotFound();
        $this->actingAs($subject)->withSession(['credential_generation' => 1, 'access_mode' => 'workspace'])
            ->post(route('account.avatar.update'), [
                'avatar' => UploadedFile::fake()->createWithContent('fake.jpg', 'not-an-image'),
            ])->assertSessionHasErrors('avatar');
    }

    public function test_expiry_is_inclusive_and_registration_email_race_never_overwrites_existing_account(): void
    {
        Mail::fake();
        $organization = $this->organization('expiry-race');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $invitation = $this->issue($owner, $organization, 'race@example.test');
        $url = $this->latestInvitationUrl();
        $this->post(route('logout'));
        $this->travelTo($invitation->expires_at);
        $this->get($url)->assertSessionHasErrors('invitation');

        $this->travelBack();
        $this->travel(61)->seconds();
        $this->asCompany($owner, $organization)->post(route('organization-management.invitations.resend', $invitation), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $newUrl = $this->latestInvitationUrl();
        $this->post(route('logout'));
        $this->get($newUrl)->assertRedirect(route('invitations.onboarding'));

        $existing = User::factory()->create(['email' => 'race@example.test', 'name' => 'Existing Wins']);
        $password = $existing->password;
        $this->post(route('invitations.register'), [
            'name' => 'Attempted Overwrite',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'));
        $this->assertSame('Existing Wins', $existing->fresh()->name);
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $organization->id, 'user_id' => $existing->id]);
    }

    public function test_sponsor_downgrade_and_wrong_or_changed_email_block_acceptance(): void
    {
        Mail::fake();
        $organization = $this->organization('sponsor');
        [$sponsor, $sponsorMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$keeper] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->asCompany($sponsor, $organization)->post(route('organization-management.standard-workspace.store'))->assertRedirect();
        $target = User::factory()->create(['email' => 'target@example.test']);
        $this->establishUnstartedProductAccount($target);
        $invitation = $this->issue($sponsor, $organization, $target->email, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $url = $this->latestInvitationUrl();

        app(OrganizationAdministration::class)->updateRole(
            $keeper,
            $organization,
            $sponsorMembership,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
        );
        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('invitations.onboarding'));
        $wrong = User::factory()->create(['email' => 'wrong@example.test']);
        $this->post(route('login'), ['email' => $wrong->email, 'password' => 'password'])->assertRedirect(route('invitations.onboarding'));
        $this->get(route('invitations.onboarding'))->assertForbidden();
        $this->post(route('logout'));

        $this->get($url)->assertRedirect(route('invitations.onboarding'));
        $this->post(route('login'), ['email' => $target->email, 'password' => 'password'])->assertRedirect(route('invitations.onboarding'));
        $this->post(route('invitations.prepare'))->assertRedirect();
        $this->post(route('invitations.accept'))->assertSessionHasErrors('invitation');
        $membership = OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $target->id)->firstOrFail();
        $this->assertSame(OrganizationUser::STATUS_INVITED, $membership->membership_status);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->fresh()->status);

        $target->forceFill(['email' => 'changed@example.test', 'email_verified_at' => now()])->save();
        $this->actingAs($target->fresh());
        $this->post(route('invitations.accept'))->assertForbidden();
        $this->assertSame(OrganizationUser::STATUS_INVITED, $membership->fresh()->membership_status);
    }

    public function test_delayed_old_generation_job_is_suppressed_and_delivery_failure_does_not_revoke_invitation(): void
    {
        Queue::fake();
        Mail::fake();
        $organization = $this->organization('queue');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $invitation = $this->issue($owner, $organization, 'queue@example.test');
        $oldJob = Queue::pushed(SendOrganizationInvitationMail::class)->first();
        $this->assertNotNull($oldJob);

        $this->travel(61)->seconds();
        $this->asCompany($owner, $organization)->post(route('organization-management.invitations.resend', $invitation), [
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect();
        $newJob = Queue::pushed(SendOrganizationInvitationMail::class)->last();
        $this->assertNotSame($oldJob, $newJob);

        $oldJob->handle(app(OrganizationInvitationMailer::class));
        Mail::assertNothingSent();
        $newJob->failed(new RuntimeException('mail unavailable'));
        $this->assertSame(OrganizationInvitation::DELIVERY_FAILED, $invitation->fresh()->delivery_status);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->fresh()->status);
        $newJob->handle(app(OrganizationInvitationMailer::class));
        Mail::assertSent(AccountActionMail::class, 1);
        $this->assertSame(OrganizationInvitation::DELIVERY_SENT, $invitation->fresh()->delivery_status);
    }

    public function test_array_development_inbox_delivers_a_real_invitation_link_that_can_be_claimed(): void
    {
        config()->set('account.mail.mailer', 'array');
        config()->set('queue.default', 'sync');
        $transport = Mail::mailer('array')->getSymfonyTransport();
        $transport->flush();
        $organization = $this->organization('mail-inbox');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);

        $this->issue($owner, $organization, 'mail-inbox@example.test');

        $this->assertCount(1, $transport->messages());
        $message = $transport->messages()->last()->getOriginalMessage();
        $html = $message->getHtmlBody();
        preg_match('/href="([^"]+)"/', $html, $matches);
        $url = html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5);
        $this->assertStringStartsWith(
            rtrim(config('app.url'), '/').'/invitations/',
            $url,
        );

        $this->post(route('logout'));
        $this->get($url)->assertRedirect(route('invitations.onboarding'));
        $this->get(route('invitations.onboarding'))
            ->assertOk()
            ->assertSee('mail-inbox@example.test');
    }

    private function organization(string $label = 'scope-four'): Organization
    {
        return Organization::create([
            'name' => 'Organization '.$label,
            'slug' => $label.'-'.strtolower((string) Str::ulid()),
        ]);
    }

    private function member(
        Organization $organization,
        string $role,
        ?User $user = null,
        string $status = OrganizationUser::STATUS_ACTIVE,
    ): array {
        $user ??= User::factory()->create();
        $membership = OrganizationUser::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role === OrganizationUser::ORGANIZATION_ROLE_OWNER ? 'owner' : 'member',
            'organization_role' => $role,
            'membership_status' => $status,
            'company_role' => OrganizationUser::COMPANY_ROLE_MEMBER,
            'permissions' => [],
            'joined_at' => $status === OrganizationUser::STATUS_ACTIVE ? now() : null,
        ]);

        return [$user, $membership];
    }

    private function asCompany(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession([
            'access_mode' => 'workspace',
            'current_company_id' => $organization->id,
            'credential_generation' => (int) $user->credential_generation,
        ]);
    }

    private function issue(
        User $actor,
        Organization $organization,
        string $email,
        ?string $role = OrganizationUser::ORGANIZATION_ROLE_MEMBER,
        array $groups = [],
    ): OrganizationInvitation {
        $payload = [
            'email' => $email,
            'group_ids' => $groups,
            'request_id' => (string) Str::uuid(),
        ];
        if ($role !== null) {
            $payload['organization_role'] = $role;
        }
        $this->asCompany($actor, $organization)
            ->post(route('organization-management.invitations.store'), $payload)
            ->assertRedirect();

        return OrganizationInvitation::query()->latest('id')->firstOrFail();
    }

    private function latestInvitationUrl(): string
    {
        $mail = Mail::sent(AccountActionMail::class)->last();
        $this->assertNotNull($mail);

        return $mail->actionUrl;
    }
}
