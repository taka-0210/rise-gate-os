<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceNameManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_admin_can_rename_any_workspace_in_admin_mode(): void
    {
        $admin = User::factory()->create(['is_system_admin' => true]);
        [, $workspace] = $this->createWorkspace();

        $this->actingAs($admin)
            ->withSession(['access_mode' => 'system_admin'])
            ->put(route('system-admin.workspaces.update', $workspace), ['name' => 'Renamed by System Admin'])
            ->assertRedirect(route('system-admin.workspaces.edit', $workspace));

        $this->assertSame('Renamed by System Admin', $workspace->fresh()->name);
    }

    #[DataProvider('privilegedWorkspaceRoles')]
    public function test_workspace_owner_and_admin_can_rename_their_workspace(string $role): void
    {
        [$user, $workspace] = $this->createWorkspace($role);

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace'])
            ->put(route('workspaces.update', $workspace), ['name' => 'Team Workspace'])
            ->assertRedirect(route('workspaces.edit', $workspace));

        $this->assertSame('Team Workspace', $workspace->fresh()->name);
    }

    public static function privilegedWorkspaceRoles(): array
    {
        return [['owner'], ['admin']];
    }

    #[DataProvider('unprivilegedWorkspaceRoles')]
    public function test_workspace_member_and_viewer_cannot_rename_workspace(string $role): void
    {
        [$user, $workspace] = $this->createWorkspace($role);

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace'])
            ->put(route('workspaces.update', $workspace), ['name' => 'Unauthorized Name'])
            ->assertForbidden();

        $this->assertSame('Original Workspace', $workspace->fresh()->name);
    }

    public static function unprivilegedWorkspaceRoles(): array
    {
        return [['member'], ['viewer']];
    }

    public function test_owner_cannot_rename_workspace_they_do_not_belong_to(): void
    {
        [$user] = $this->createWorkspace('owner');
        [, $otherWorkspace] = $this->createWorkspace('owner', 'Other Org', 'Other Workspace');

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace'])
            ->put(route('workspaces.update', $otherWorkspace), ['name' => 'Unauthorized Name'])
            ->assertForbidden();

        $this->assertSame('Other Workspace', $otherWorkspace->fresh()->name);
    }

    private function createWorkspace(
        string $role = 'owner',
        string $organizationName = 'Original Organization',
        string $workspaceName = 'Original Workspace'
    ): array {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => $organizationName,
            'slug' => str($organizationName)->slug().'-'.uniqid(),
        ]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'name' => $workspaceName,
            'slug' => str($workspaceName)->slug().'-'.uniqid(),
        ]);
        $organization->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);

        return [$user, $workspace];
    }
}
