<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_project_in_current_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post('/projects', [
                'name' => 'Website Improvement Project',
                'code' => 'RG-001',
                'summary' => 'The first project centered on improvement.',
                'status' => 'active',
                'priority' => 'high',
                'start_date' => '2026-07-13',
                'due_date' => '2026-08-13',
            ]);

        $project = Project::firstOrFail();

        $response->assertRedirect(route('projects.show', $project));
        $this->assertSame($workspace->id, $project->owning_workspace_id);
        $this->assertSame($workspace->id, $project->billing_workspace_id);
        $this->assertSame($workspace->organization_id, $project->organization_id);
        $this->assertSame($user->id, $project->owner_user_id);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => 'owner',
            'permission_level' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_project_list_only_shows_joined_projects_for_current_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other@example.com');

        $visibleProject = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'Current Workspace Project',
        ]);
        $this->addProjectMember($visibleProject, $user, $workspace, 'owner', 'admin');

        $hiddenProject = Project::create([
            'organization_id' => $otherWorkspace->organization_id,
            'owning_workspace_id' => $otherWorkspace->id,
            'billing_workspace_id' => $otherWorkspace->id,
            'owner_user_id' => $otherUser->id,
            'name' => 'Other Workspace Project',
        ]);
        $this->addProjectMember($hiddenProject, $otherUser, $otherWorkspace, 'owner', 'admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/projects');

        $response->assertOk();
        $response->assertSee('Current Workspace Project');
        $response->assertDontSee('Other Workspace Project');
    }

    public function test_user_cannot_view_project_without_project_membership(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other@example.com');

        $project = Project::create([
            'organization_id' => $otherWorkspace->organization_id,
            'owning_workspace_id' => $otherWorkspace->id,
            'billing_workspace_id' => $otherWorkspace->id,
            'owner_user_id' => $otherUser->id,
            'name' => 'Private Project',
        ]);
        $this->addProjectMember($project, $otherUser, $otherWorkspace, 'owner', 'admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project));

        $response->assertForbidden();
    }

    public function test_project_admin_can_update_project(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'Original Project',
            'status' => 'active',
            'priority' => 'normal',
        ]);
        $this->addProjectMember($project, $user, $workspace, 'owner', 'admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('projects.update', $project), [
                'client_id' => null,
                'name' => 'Updated Project',
                'code' => 'UP-001',
                'summary' => 'Updated summary for operation.',
                'status' => 'on_hold',
                'priority' => 'high',
                'start_date' => '2026-07-14',
                'due_date' => '2026-08-14',
            ]);

        $response->assertRedirect(route('projects.show', $project));
        $project->refresh();
        $this->assertSame('Updated Project', $project->name);
        $this->assertSame('UP-001', $project->code);
        $this->assertSame('on_hold', $project->status);
        $this->assertSame('high', $project->priority);
    }

    public function test_view_only_project_member_cannot_update_project(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Protected Project',
            'status' => 'active',
            'priority' => 'normal',
        ]);
        $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');
        $this->addProjectMember($project, $viewer, $viewerWorkspace, 'viewer', 'view');

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->put(route('projects.update', $project), [
                'client_id' => null,
                'name' => 'Should Not Update',
                'status' => 'active',
                'priority' => 'normal',
            ])
            ->assertForbidden();
    }
    protected function createWorkspaceOwner(
        string $organizationName = 'Rise Gate',
        string $workspaceName = 'Rise Gate Workspace',
        string $email = 'takami@example.com'
    ): array {
        $user = User::factory()->create(['email' => $email]);
        $organization = Organization::create([
            'name' => $organizationName,
            'slug' => str($organizationName)->slug().'-'.uniqid(),
        ]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'name' => $workspaceName,
            'slug' => str($workspaceName)->slug().'-'.uniqid(),
        ]);

        $organization->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    protected function addProjectMember(Project $project, User $user, Workspace $workspace, string $role, string $permission): ProjectMember
    {
        return ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => $role,
            'permission_level' => $permission,
            'invited_by' => $project->owner_user_id,
            'invited_at' => now(),
            'accepted_at' => now(),
            'status' => 'active',
        ]);
    }
}

