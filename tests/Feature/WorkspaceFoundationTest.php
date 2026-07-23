<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_owner_workspace_and_sets_current_workspace(): void
    {
        $response = $this->post('/register', [
            'name' => 'Takami Masaya',
            'email' => 'takami@example.com',
            'organization_name' => 'Rise Gate',
            'workspace_name' => 'Rise Gate Workspace',
            'password' => 'password-test',
            'password_confirmation' => 'password-test',
        ]);

        $response->assertRedirect(route('company.home'));

        $user = User::where('email', 'takami@example.com')->firstOrFail();
        $organization = Organization::where('name', 'Rise Gate')->firstOrFail();
        $workspace = Workspace::where('name', 'Rise Gate Workspace')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->is_system_admin);
        $this->assertSame($organization->id, $workspace->organization_id);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $response->assertSessionHas('current_workspace_id', $workspace->id);
        $response->assertSessionHas('current_company_id', $organization->id);
    }

    public function test_self_registration_is_closed_after_the_first_user(): void
    {
        User::factory()->create();

        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Second User',
            'email' => 'second@example.com',
            'organization_name' => 'Second Organization',
            'workspace_name' => 'Second Workspace',
            'password' => 'password-test',
            'password_confirmation' => 'password-test',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'second@example.com']);
    }

    public function test_dashboard_requires_current_workspace(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Rise Gate',
            'slug' => 'rise-gate',
        ]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'name' => 'Main Workspace',
            'slug' => 'main-workspace',
        ]);

        $organization->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee('Main Workspace');
        $response->assertSessionHas('current_workspace_id', $workspace->id);
    }

    public function test_workspace_list_shows_dashboard_summary_and_name_change_link(): void
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Summary Org', 'slug' => 'summary-org']);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'owner_user_id' => $user->id, 'name' => 'Summary Workspace', 'slug' => 'summary-workspace']);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace'])
            ->get(route('workspaces.index'))
            ->assertOk()
            ->assertSee('Project')
            ->assertSee('クライアント')
            ->assertSee('育成中')
            ->assertSee('今週追加')
            ->assertSee('（名前変更）');
    }
}
