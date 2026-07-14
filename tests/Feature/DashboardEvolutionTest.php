<?php

namespace Tests\Feature;

use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardEvolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_evolution_dashboard_shows_growth_from_existing_project_and_improvement_data(): void
    {
        Carbon::setTestNow('2026-07-14 10:00:00');

        [$user, $workspace] = $this->createWorkspaceOwner();
        $project = $this->createProjectWithOwner($user, $workspace, 'Rise Gate OS');

        Improvement::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Evolution Dashboard改善',
            'problem' => 'Dashboardが古い仮画面のままです。',
            'status' => Improvement::STATUS_PROPOSED,
            'visibility' => Improvement::VISIBILITY_INTERNAL,
            'proposed_by' => $user->id,
            'assigned_to' => $user->id,
        ]);

        Improvement::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '改善結果を残す',
            'result' => 'Dashboardの方向性を整理しました。',
            'status' => Improvement::STATUS_IMPLEMENTED,
            'visibility' => Improvement::VISIBILITY_INTERNAL,
            'proposed_by' => $user->id,
            'implemented_by' => $user->id,
            'implemented_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/dashboard');

        $response->assertOk();
        $response->assertSee('今週、会社は');
        $response->assertSee('Rise Gate OSからの一言');
        $response->assertSee('会社の現在地');
        $response->assertSee('次に育てる改善');
        $response->assertSee('Evolution Dashboard改善');
        $response->assertSee('最後に生まれた改善');
    }

    public function test_client_role_dashboard_only_shows_client_visible_improvements(): void
    {
        Carbon::setTestNow('2026-07-14 10:00:00');

        [$owner, $ownerWorkspace] = $this->createWorkspaceOwner('Owner Org', 'Owner Workspace', 'owner@example.com');
        [$clientUser, $clientWorkspace] = $this->createWorkspaceOwner('Client Org', 'Client Workspace', 'client@example.com');
        $project = $this->createProjectWithOwner($owner, $ownerWorkspace, 'Client Shared Project');
        $this->addProjectMember($project, $clientUser, $clientWorkspace, ProjectMember::ROLE_CLIENT, ProjectMember::PERMISSION_VIEW);

        Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => '社内限定の改善',
            'status' => Improvement::STATUS_PROPOSED,
            'visibility' => Improvement::VISIBILITY_INTERNAL,
            'proposed_by' => $owner->id,
        ]);

        Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => 'お客様と育てる改善',
            'status' => Improvement::STATUS_PROPOSED,
            'visibility' => Improvement::VISIBILITY_CLIENT,
            'proposed_by' => $owner->id,
        ]);

        $response = $this
            ->actingAs($clientUser)
            ->withSession(['current_workspace_id' => $clientWorkspace->id])
            ->get('/dashboard');

        $response->assertOk();
        $response->assertSee('お客様と育てる改善');
        $response->assertDontSee('社内限定の改善');
    }

    private function createWorkspaceOwner(
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

        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$user, $workspace];
    }

    private function createProjectWithOwner(User $owner, Workspace $workspace, string $name): Project
    {
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => $name,
            'status' => Project::STATUS_ACTIVE,
        ]);

        $this->addProjectMember($project, $owner, $workspace, ProjectMember::ROLE_OWNER, ProjectMember::PERMISSION_ADMIN);

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
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
    }
}
