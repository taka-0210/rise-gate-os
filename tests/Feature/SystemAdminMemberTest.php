<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemAdminMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_direct_staff_registration_is_retired_in_ui_and_http(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);

        $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->get(route('system-admin.members.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('system-admin.members.store').'"', false)
            ->assertDontSee('name="password"', false);

        $response = $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->post(route('system-admin.members.store'), [
                'name' => 'New Partner',
                'email' => 'partner@example.com',
                'password' => 'password-test',
                'password_confirmation' => 'password-test',
                'assignment_type' => 'new_workspace',
                'organization_name' => 'Partner Company',
                'workspace_name' => 'Partner Workspace',
            ]);

        $response->assertGone();
        $this->assertDatabaseMissing('users', ['email' => 'partner@example.com']);
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('workspaces', 0);
    }

    public function test_first_account_bootstrap_remains_available(): void
    {
        $this->get(route('register'))->assertOk();

        $this->post(route('register'), [
            'name' => 'Bootstrap Owner',
            'email' => 'bootstrap@example.com',
            'password' => 'password-test',
            'password_confirmation' => 'password-test',
            'organization_name' => 'Bootstrap Organization',
            'workspace_name' => 'Bootstrap Workspace',
        ])->assertRedirect(route('company.home'));

        $user = User::where('email', 'bootstrap@example.com')->firstOrFail();
        $organization = Organization::where('name', 'Bootstrap Organization')->firstOrFail();
        $workspace = Workspace::where('name', 'Bootstrap Workspace')->firstOrFail();

        $this->assertTrue($user->is_system_admin);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => OrganizationUser::ROLE_OWNER,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
    }

    public function test_system_admin_cannot_create_a_new_account_in_an_existing_workspace(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'name' => 'Main', 'slug' => 'main']);

        $response = $this->actingAs($admin)->withSession(['access_mode' => 'system_admin'])->post(route('system-admin.members.store'), [
            'name' => 'Staff Member',
            'email' => 'staff@example.com',
            'password' => 'password-test',
            'password_confirmation' => 'password-test',
            'assignment_type' => 'existing_workspace',
            'workspace_id' => $workspace->id,
            'workspace_role' => 'member',
        ]);

        $response->assertGone();
        $this->assertDatabaseMissing('users', ['email' => 'staff@example.com']);
        $this->assertDatabaseCount('organization_users', 0);
        $this->assertDatabaseCount('workspace_members', 0);
        $this->assertDatabaseCount('workspaces', 1);
    }

    public function test_non_system_admin_cannot_access_system_admin_members(): void
    {
        $user = User::factory()->create(['is_system_admin' => false]);

        $this->actingAs($user)->withSession(['access_mode' => 'system_admin'])->get(route('system-admin.members.index'))->assertForbidden();
        $this->actingAs($user)->withSession(['access_mode' => 'system_admin'])->post(route('system-admin.members.store'), [])->assertForbidden();
    }

    public function test_system_admin_can_update_member_account_without_changing_password(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $member = User::factory()->create(['is_active' => true, 'password' => 'original-password']);
        $originalPassword = $member->password;

        $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->get(route('system-admin.members.edit', $member))
            ->assertOk()
            ->assertDontSee('name="password"', false);

        $this->actingAs($admin)->withSession(['access_mode' => 'system_admin'])->put(route('system-admin.members.update', $member), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'is_system_admin' => '1',
            'is_active' => '1',
        ])->assertRedirect(route('system-admin.members.edit', $member));

        $member->refresh();
        $this->assertSame('Updated Name', $member->name);
        $this->assertSame('updated@example.com', $member->email);
        $this->assertTrue($member->is_system_admin);
        $this->assertSame($originalPassword, $member->password);
        $this->assertSame(2, $member->credential_generation);
        $this->assertNull($member->email_verified_at);
    }

    public function test_system_admin_cannot_assign_a_permanent_password(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $member = User::factory()->create(['is_active' => true, 'password' => 'original-password']);
        $originalPassword = $member->password;

        $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->from(route('system-admin.members.edit', $member))
            ->put(route('system-admin.members.update', $member), [
                'name' => 'Tampered Name',
                'email' => $member->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
                'is_system_admin' => '0',
                'is_active' => '1',
            ])
            ->assertRedirect(route('system-admin.members.edit', $member))
            ->assertSessionHasErrors(['password', 'password_confirmation']);

        $member->refresh();
        $this->assertSame($originalPassword, $member->password);
        $this->assertNotSame('Tampered Name', $member->name);
        $this->assertSame(1, $member->credential_generation);
        $this->assertFalse(Hash::check('new-password', $member->password));
    }

    public function test_last_active_system_admin_cannot_be_demoted_or_suspended(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true, 'is_active' => true]);

        $response = $this->actingAs($admin)->withSession(['access_mode' => 'system_admin'])->from(route('system-admin.members.edit', $admin))->put(route('system-admin.members.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_system_admin' => '0',
            'is_active' => '0',
        ]);

        $response->assertRedirect(route('system-admin.members.edit', $admin));
        $response->assertSessionHasErrors('is_system_admin');
        $this->assertTrue($admin->fresh()->is_system_admin);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_existing_account_workspace_membership_management_remains_available_after_direct_creation_retirement(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $member = User::factory()->create();
        $this->establishUnstartedProductAccount($member);
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'name' => 'Main', 'slug' => 'main']);

        $this->actingAs($admin)->withSession(['access_mode' => 'system_admin'])->post(route('system-admin.members.workspaces.store', $member), [
            'workspace_id' => $workspace->id,
            'workspace_role' => 'member',
        ])->assertRedirect();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($admin)->withSession(['access_mode' => 'system_admin'])->put(route('system-admin.members.workspaces.update', [$member, $workspace]), [
            'workspace_role' => 'admin',
        ])->assertRedirect();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'admin']);

        $this->actingAs($admin)->withSession(['access_mode' => 'system_admin'])->delete(route('system-admin.members.workspaces.destroy', [$member, $workspace]))->assertRedirect();
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $member->id]);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
        ]);
    }

    public function test_adding_another_workspace_does_not_downgrade_existing_organization_roles(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $member = User::factory()->create();
        $organization = Organization::create(['name' => 'Existing Owner Org', 'slug' => 'existing-owner-org']);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'name' => 'Second Workspace',
            'slug' => 'second-workspace',
        ]);
        OrganizationUser::create([
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => OrganizationUser::ROLE_OWNER,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]);

        $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->post(route('system-admin.members.workspaces.store', $member), [
                'workspace_id' => $workspace->id,
                'workspace_role' => 'member',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'role' => OrganizationUser::ROLE_OWNER,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
        ]);
    }

    public function test_last_workspace_owner_cannot_be_removed(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Owner Org', 'slug' => 'owner-org']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'name' => 'Owner Workspace', 'slug' => 'owner-workspace']);
        $organization->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->from(route('system-admin.members.edit', $owner))
            ->delete(route('system-admin.members.workspaces.destroy', [$owner, $workspace]))
            ->assertSessionHasErrors('workspace_role');

        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'role' => 'owner']);
    }

    public function test_suspended_member_cannot_log_in(): void
    {
        $member = User::factory()->create([
            'email' => 'suspended@example.com',
            'password' => 'password-test',
            'is_active' => false,
        ]);

        $this->post('/login', ['email' => $member->email, 'password' => 'password-test'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_system_admin_login_enters_admin_mode(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password-test',
            'is_system_admin' => true,
        ]);

        $this->post(route('system-admin.login.store'), [
            'email' => $admin->email,
            'password' => 'password-test',
        ])->assertRedirect(route('system-admin.members.index'))
            ->assertSessionHas('access_mode', 'system_admin');

        $this->assertAuthenticatedAs($admin);
        $this->get(route('system-admin.members.index'))->assertOk()->assertSee('System Admin Mode');
        $this->get('/dashboard')->assertForbidden();
    }

    public function test_regular_login_cannot_open_system_admin_area_without_admin_login(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => 'password-test',
            'is_system_admin' => true,
        ]);

        $this->post('/login', ['email' => $admin->email, 'password' => 'password-test'])
            ->assertSessionHas('access_mode', 'workspace');

        $this->get(route('system-admin.members.index'))->assertForbidden();
    }

    public function test_non_admin_cannot_use_system_admin_login(): void
    {
        $member = User::factory()->create([
            'email' => 'member@example.com',
            'password' => 'password-test',
            'is_system_admin' => false,
        ]);

        $this->post(route('system-admin.login.store'), [
            'email' => $member->email,
            'password' => 'password-test',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
