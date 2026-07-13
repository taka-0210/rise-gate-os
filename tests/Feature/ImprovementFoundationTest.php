<?php

namespace Tests\Feature;

use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImprovementFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_create_improvement(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        $project = $this->createProjectWithOwner($owner, $workspace);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.improvements.store', $project), [
                'title' => 'Improve onboarding flow',
                'current_state' => 'Customers ask the same setup questions repeatedly.',
                'desired_state' => 'Customers can understand the next step from the Project.',
                'problem' => 'Information is scattered across chat and email.',
                'hypothesis' => 'A shared improvement note will reduce uncertainty.',
                'action' => 'Create a visible improvement record.',
                'result' => 'Pending measurement.',
                'impact' => 'Better shared context.',
                'next_action' => 'Review with the client.',
                'status' => 'proposed',
                'visibility' => 'internal',
                'assigned_to' => $owner->id,
            ]);

        $improvement = Improvement::firstOrFail();

        $response->assertRedirect(route('projects.improvements.show', [$project, $improvement]));
        $this->assertSame($workspace->organization_id, $improvement->organization_id);
        $this->assertSame($workspace->id, $improvement->workspace_id);
        $this->assertSame($project->id, $improvement->project_id);
        $this->assertSame($owner->id, $improvement->proposed_by);
        $this->assertSame($owner->id, $improvement->assigned_to);
    }

    public function test_project_view_member_can_view_but_not_create_improvement(): void
    {
        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace);
        $this->addProjectMember($project, $viewer, $viewerWorkspace, 'viewer', 'view');
        Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => 'Visible project improvement',
            'status' => 'proposed',
            'visibility' => 'project',
            'proposed_by' => $owner->id,
        ]);

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->get(route('projects.improvements.index', $project))
            ->assertOk()
            ->assertSee('Visible project improvement');

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->post(route('projects.improvements.store', $project), [
                'title' => 'Viewer proposal',
                'status' => 'proposed',
                'visibility' => 'project',
            ])
            ->assertForbidden();
    }

    public function test_client_role_only_sees_client_visible_improvements(): void
    {
        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$clientUser, $clientWorkspace] = $this->createWorkspaceOwner('Client Org', 'Client Workspace', 'client@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace);
        $this->addProjectMember($project, $clientUser, $clientWorkspace, 'client', 'view');

        $internalImprovement = Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => 'Internal cost improvement',
            'status' => 'proposed',
            'visibility' => 'internal',
            'proposed_by' => $owner->id,
        ]);
        $clientImprovement = Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => 'Client shared improvement',
            'status' => 'proposed',
            'visibility' => 'client',
            'proposed_by' => $owner->id,
        ]);

        $this
            ->actingAs($clientUser)
            ->withSession(['current_workspace_id' => $clientWorkspace->id])
            ->get(route('projects.improvements.index', $project))
            ->assertOk()
            ->assertSee('Client shared improvement')
            ->assertDontSee('Internal cost improvement');

        $this
            ->actingAs($clientUser)
            ->withSession(['current_workspace_id' => $clientWorkspace->id])
            ->get(route('projects.improvements.show', [$project, $clientImprovement]))
            ->assertOk();

        $this
            ->actingAs($clientUser)
            ->withSession(['current_workspace_id' => $clientWorkspace->id])
            ->get(route('projects.improvements.show', [$project, $internalImprovement]))
            ->assertForbidden();
    }

    public function test_improvement_assignee_must_be_project_member(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$outsider] = $this->createWorkspaceOwner('Outside Org', 'Outside Workspace', 'outside@example.com');
        $project = $this->createProjectWithOwner($owner, $workspace);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.improvements.store', $project), [
                'title' => 'Invalid assignment',
                'status' => 'proposed',
                'visibility' => 'internal',
                'assigned_to' => $outsider->id,
            ]);

        $response->assertSessionHasErrors('assigned_to');
        $this->assertDatabaseMissing('improvements', [
            'title' => 'Invalid assignment',
        ]);
    }

    public function test_project_member_can_update_improvement(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        $project = $this->createProjectWithOwner($owner, $workspace);
        $improvement = Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => 'Original improvement',
            'status' => 'proposed',
            'visibility' => 'internal',
            'proposed_by' => $owner->id,
        ]);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('projects.improvements.update', [$project, $improvement]), [
                'title' => 'Updated improvement',
                'current_state' => 'Current state was recorded.',
                'desired_state' => 'Desired state was clarified.',
                'problem' => 'Problem was clarified.',
                'hypothesis' => 'Hypothesis was updated.',
                'action' => 'Action was taken.',
                'result' => 'Result was recorded.',
                'impact' => 'Impact was recorded.',
                'next_action' => 'Create the next improvement.',
                'status' => 'implemented',
                'visibility' => 'internal',
                'assigned_to' => $owner->id,
                'implemented_by' => $owner->id,
                'implemented_at' => '2026-07-14 10:00:00',
            ]);

        $response->assertRedirect(route('projects.improvements.show', [$project, $improvement]));
        $improvement->refresh();
        $this->assertSame('Updated improvement', $improvement->title);
        $this->assertSame('implemented', $improvement->status);
        $this->assertSame('Result was recorded.', $improvement->result);
        $this->assertSame('Impact was recorded.', $improvement->impact);
        $this->assertSame('Create the next improvement.', $improvement->next_action);
    }

    public function test_view_only_project_member_cannot_update_improvement(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        $project = $this->createProjectWithOwner($owner, $workspace);
        $this->addProjectMember($project, $viewer, $viewerWorkspace, 'viewer', 'view');
        $improvement = Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => 'Protected improvement',
            'status' => 'proposed',
            'visibility' => 'project',
            'proposed_by' => $owner->id,
        ]);

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->put(route('projects.improvements.update', [$project, $improvement]), [
                'title' => 'Should Not Update',
                'status' => 'implemented',
                'visibility' => 'project',
            ])
            ->assertForbidden();
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

