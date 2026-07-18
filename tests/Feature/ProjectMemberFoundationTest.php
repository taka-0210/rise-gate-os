<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectMemberFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_admin_can_add_member_from_another_workspace(): void
    {
        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$partner, $partnerWorkspace] = $this->createWorkspaceOwner('Partner Org', 'Partner Workspace', 'partner@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $ownerWorkspace->id])
            ->post(route('projects.members.store', $project), [
                'email' => $partner->email,
                'project_role' => 'coder',
                'permission_level' => 'edit',
            ]);

        $response->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $partner->id,
            'workspace_id' => $partnerWorkspace->id,
            'project_role' => 'coder',
            'permission_level' => 'edit',
            'status' => 'active',
        ]);
    }

    public function test_project_admin_can_preview_member_before_adding(): void
    {
        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$partner, $partnerWorkspace] = $this->createWorkspaceOwner('Partner Org', 'Partner Workspace', 'partner@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $ownerWorkspace->id])
            ->get(route('projects.legacy', [
                'project' => $project,
                'member_email' => $partner->email,
                'project_role' => 'coder',
                'permission_level' => 'edit',
            ]));

        $response->assertOk();
        $response->assertSee('追加対象の確認');
        $response->assertSee($partner->name);
        $response->assertSee($partner->email);
        $response->assertSee($partnerWorkspace->name);
        $response->assertSee('実装担当');
        $response->assertSee('編集');
        $response->assertSee('この内容で追加');
    }
    public function test_project_member_can_view_cross_workspace_project_from_their_workspace(): void
    {
        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$partner, $partnerWorkspace] = $this->createWorkspaceOwner('Partner Org', 'Partner Workspace', 'partner@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace);
        $this->addProjectMember($project, $partner, $partnerWorkspace, 'reviewer', 'comment');

        $response = $this
            ->actingAs($partner)
            ->withSession(['current_workspace_id' => $partnerWorkspace->id])
            ->get(route('projects.show', $project));

        $response->assertOk();
        $response->assertSee('Shared Project');
        $response->assertSee('Partner Workspace');
    }

    public function test_non_admin_project_member_cannot_add_members(): void
    {
        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        [$candidate] = $this->createWorkspaceOwner('Candidate Org', 'Candidate Workspace', 'candidate@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace);
        $this->addProjectMember($project, $viewer, $viewerWorkspace, 'viewer', 'view');

        $response = $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->post(route('projects.members.store', $project), [
                'email' => $candidate->email,
                'project_role' => 'designer',
                'permission_level' => 'edit',
            ]);

        $response->assertForbidden();
    }

    public function test_admin_can_remove_member_but_not_project_owner(): void
    {
        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$partner, $partnerWorkspace] = $this->createWorkspaceOwner('Partner Org', 'Partner Workspace', 'partner@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace);
        $ownerMember = $project->members()->where('user_id', $owner->id)->firstOrFail();
        $partnerMember = $this->addProjectMember($project, $partner, $partnerWorkspace, 'coder', 'edit');

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $ownerWorkspace->id])
            ->delete(route('projects.members.destroy', [$project, $partnerMember]))
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseMissing('project_members', ['id' => $partnerMember->id]);

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $ownerWorkspace->id])
            ->delete(route('projects.members.destroy', [$project, $ownerMember]))
            ->assertSessionHasErrors('member');

        $this->assertDatabaseHas('project_members', ['id' => $ownerMember->id]);
    }

    private function createWorkspaceOwner(string $organizationName, string $workspaceName, string $email): array
    {
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

        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$user, $workspace];
    }

    private function createProjectWithOwner(User $owner, Workspace $workspace): Project
    {
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Shared Project',
            'status' => 'active',
        ]);

        $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');

        return $project;
    }

    private function addProjectMember(Project $project, User $user, Workspace $workspace, string $role, string $permission): ProjectMember
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

