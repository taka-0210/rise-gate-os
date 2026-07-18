<?php

namespace Tests\Feature;

use App\Models\Improvement;
use App\Models\ImprovementOutput;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImprovementOutputFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_create_project_task(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = $this->createProjectWithOwner($owner, $workspace);
        $improvement = $this->createImprovement($project, $owner);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.tasks.store', $project), [
                'improvement_id' => $improvement->id,
                'title' => 'Prepare dashboard review',
                'description' => 'Review the next evolution step.',
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_HIGH,
                'assigned_to' => $owner->id,
                'due_date' => '2026-07-20',
            ]);

        $response->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('tasks', [
            'project_id' => $project->id,
            'improvement_id' => $improvement->id,
            'title' => 'Prepare dashboard review',
            'assigned_to' => $owner->id,
        ]);
    }

    public function test_improvement_can_create_multiple_task_outputs(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = $this->createProjectWithOwner($owner, $workspace);
        $improvement = $this->createImprovement($project, $owner);

        foreach (['Write operation note', 'Review next action'] as $title) {
            $this
                ->actingAs($owner)
                ->withSession(['current_workspace_id' => $workspace->id])
                ->post(route('projects.improvements.outputs.tasks.store', [$project, $improvement]), [
                    'title' => $title,
                    'description' => 'Created from improvement output.',
                    'status' => Task::STATUS_TODO,
                    'priority' => Task::PRIORITY_NORMAL,
                    'assigned_to' => $owner->id,
                ])
                ->assertRedirect(route('projects.improvements.show', [$project, $improvement]));
        }

        $this->assertSame(2, Task::where('improvement_id', $improvement->id)->count());
        $this->assertSame(2, ImprovementOutput::where('improvement_id', $improvement->id)->where('output_type', ImprovementOutput::TYPE_TASK)->count());

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.improvements.show', [$project, $improvement]))
            ->assertOk()
            ->assertSee('Write operation note')
            ->assertSee('Review next action');
    }

    public function test_improvement_can_create_project_output(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $sourceProject = $this->createProjectWithOwner($owner, $workspace);
        $improvement = $this->createImprovement($sourceProject, $owner);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.improvements.outputs.projects.store', [$sourceProject, $improvement]), [
                'name' => 'SNS Operation System',
                'code' => 'SNS-001',
                'summary' => 'A new project born from an improvement.',
                'status' => Project::STATUS_DRAFT,
                'priority' => Project::PRIORITY_HIGH,
                'start_date' => '2026-07-21',
                'due_date' => '2026-08-21',
            ]);

        $newProject = Project::where('name', 'SNS Operation System')->firstOrFail();

        $response->assertRedirect(route('projects.show', $newProject));
        $this->assertSame($sourceProject->owning_workspace_id, $newProject->owning_workspace_id);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $newProject->id,
            'user_id' => $owner->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('roadmaps', [
            'project_id' => $newProject->id,
            'title' => 'プロジェクトを前に進める',
        ]);
        $this->assertDatabaseHas('improvements', [
            'project_id' => $newProject->id,
            'title' => '進めるための具体的な動き',
        ]);
        $this->assertDatabaseHas('improvement_outputs', [
            'improvement_id' => $improvement->id,
            'output_type' => ImprovementOutput::TYPE_PROJECT,
            'output_id' => $newProject->id,
        ]);

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.improvements.show', [$sourceProject, $improvement]))
            ->assertOk()
            ->assertSee('SNS Operation System');
    }

    public function test_view_only_project_member_cannot_create_outputs(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        $project = $this->createProjectWithOwner($owner, $workspace);
        $this->addProjectMember($project, $viewer, $viewerWorkspace, ProjectMember::ROLE_VIEWER, ProjectMember::PERMISSION_VIEW);
        $improvement = $this->createImprovement($project, $owner);

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->post(route('projects.tasks.store', $project), [
                'title' => 'Should not create task',
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_NORMAL,
            ])
            ->assertForbidden();

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->post(route('projects.improvements.outputs.tasks.store', [$project, $improvement]), [
                'title' => 'Should not create output',
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_NORMAL,
            ])
            ->assertForbidden();
    }

    public function test_project_created_from_improvement_shows_origin_on_project_list_and_detail(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $sourceProject = $this->createProjectWithOwner($owner, $workspace);
        $improvement = $this->createImprovement($sourceProject, $owner);

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.improvements.outputs.projects.store', [$sourceProject, $improvement]), [
                'name' => 'Derived Project From Improvement',
                'status' => Project::STATUS_DRAFT,
                'priority' => Project::PRIORITY_NORMAL,
            ]);

        $derivedProject = Project::where('name', 'Derived Project From Improvement')->firstOrFail();

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Derived Project From Improvement')
            ->assertSee('改善から生まれたProject')
            ->assertSee($improvement->title);

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $derivedProject))
            ->assertOk()
            ->assertSee('このProjectの起点')
            ->assertSee($sourceProject->name)
            ->assertSee($improvement->title);
    }

    public function test_project_origin_does_not_leak_internal_improvement_to_unrelated_member(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        $sourceProject = $this->createProjectWithOwner($owner, $workspace);
        $improvement = $this->createImprovement($sourceProject, $owner);

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.improvements.outputs.projects.store', [$sourceProject, $improvement]), [
                'name' => 'Shared Derived Project',
                'status' => Project::STATUS_DRAFT,
                'priority' => Project::PRIORITY_NORMAL,
            ]);

        $derivedProject = Project::where('name', 'Shared Derived Project')->firstOrFail();
        $this->addProjectMember($derivedProject, $viewer, $viewerWorkspace, ProjectMember::ROLE_VIEWER, ProjectMember::PERMISSION_VIEW);

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->get(route('projects.show', $derivedProject))
            ->assertOk()
            ->assertSee('改善から生まれたProject')
            ->assertSee('起点となった改善は公開範囲により表示されません。')
            ->assertDontSee($improvement->title)
            ->assertDontSee($sourceProject->name);
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

    private function createProjectWithOwner(User $owner, Workspace $workspace): Project
    {
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Source Project',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_NORMAL,
        ]);

        $this->addProjectMember($project, $owner, $workspace, ProjectMember::ROLE_OWNER, ProjectMember::PERMISSION_ADMIN);

        return $project;
    }

    private function createImprovement(Project $project, User $owner): Improvement
    {
        $roadmap = Roadmap::firstOrCreate(
            ['project_id' => $project->id, 'title' => 'プロジェクトを前に進める'],
            [
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'status' => Roadmap::STATUS_ACTIVE,
                'sort_order' => 1,
                'created_by' => $owner->id,
            ]
        );

        return Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'roadmap_sort_order' => 1,
            'title' => 'Create a new company activity',
            'status' => Improvement::STATUS_PROPOSED,
            'visibility' => Improvement::VISIBILITY_INTERNAL,
            'proposed_by' => $owner->id,
        ]);
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
