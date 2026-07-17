<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemAdminMemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_register_member_with_a_new_workspace(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);

        $response = $this->actingAs($admin)->post(route('system-admin.members.store'), [
            'name' => 'New Partner',
            'email' => 'partner@example.com',
            'password' => 'password-test',
            'password_confirmation' => 'password-test',
            'assignment_type' => 'new_workspace',
            'organization_name' => 'Partner Company',
            'workspace_name' => 'Partner Workspace',
        ]);

        $response->assertRedirect(route('system-admin.members.index'));
        $user = User::where('email', 'partner@example.com')->firstOrFail();
        $organization = Organization::where('name', 'Partner Company')->firstOrFail();
        $workspace = Workspace::where('name', 'Partner Workspace')->firstOrFail();

        $this->assertFalse($user->is_system_admin);
        $this->assertSame($organization->id, $workspace->organization_id);
        $this->assertDatabaseHas('organization_users', ['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'owner']);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
    }

    public function test_system_admin_can_register_member_in_an_existing_workspace(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'name' => 'Main', 'slug' => 'main']);

        $response = $this->actingAs($admin)->post(route('system-admin.members.store'), [
            'name' => 'Staff Member',
            'email' => 'staff@example.com',
            'password' => 'password-test',
            'password_confirmation' => 'password-test',
            'assignment_type' => 'existing_workspace',
            'workspace_id' => $workspace->id,
            'workspace_role' => 'member',
        ]);

        $response->assertRedirect(route('system-admin.members.index'));
        $user = User::where('email', 'staff@example.com')->firstOrFail();
        $this->assertDatabaseHas('organization_users', ['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'member']);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);
        $this->assertDatabaseCount('workspaces', 1);
    }

    public function test_non_system_admin_cannot_access_system_admin_members(): void
    {
        $user = User::factory()->create(['is_system_admin' => false]);

        $this->actingAs($user)->get(route('system-admin.members.index'))->assertForbidden();
        $this->actingAs($user)->post(route('system-admin.members.store'), [])->assertForbidden();
    }

    public function test_system_admin_can_update_member_account_and_password(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $member = User::factory()->create(['is_active' => true]);

        $this->actingAs($admin)->put(route('system-admin.members.update', $member), [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'is_system_admin' => '1',
            'is_active' => '1',
        ])->assertRedirect(route('system-admin.members.edit', $member));

        $member->refresh();
        $this->assertSame('Updated Name', $member->name);
        $this->assertSame('updated@example.com', $member->email);
        $this->assertTrue($member->is_system_admin);
        $this->assertTrue(Hash::check('new-password', $member->password));
    }

    public function test_last_active_system_admin_cannot_be_demoted_or_suspended(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true, 'is_active' => true]);

        $response = $this->actingAs($admin)->from(route('system-admin.members.edit', $admin))->put(route('system-admin.members.update', $admin), [
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

    public function test_system_admin_can_add_update_and_remove_workspace_membership(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        $member = User::factory()->create();
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'name' => 'Main', 'slug' => 'main']);

        $this->actingAs($admin)->post(route('system-admin.members.workspaces.store', $member), [
            'workspace_id' => $workspace->id,
            'workspace_role' => 'member',
        ])->assertRedirect();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($admin)->put(route('system-admin.members.workspaces.update', [$member, $workspace]), [
            'workspace_role' => 'admin',
        ])->assertRedirect();
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $member->id, 'role' => 'admin']);

        $this->actingAs($admin)->delete(route('system-admin.members.workspaces.destroy', [$member, $workspace]))->assertRedirect();
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $member->id]);
        $this->assertDatabaseMissing('organization_users', ['organization_id' => $organization->id, 'user_id' => $member->id]);
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
}
