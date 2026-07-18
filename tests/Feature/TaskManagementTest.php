<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_editor_can_view_edit_and_update_task(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $task = $this->createTask($project, $owner);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.tasks.show', [$project, $task]))
            ->assertOk()
            ->assertSee($task->title)
            ->assertSee('編集する');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('projects.tasks.update', [$project, $task]), [
                'title' => '更新したTask',
                'description' => '更新した説明',
                'status' => Task::STATUS_DONE,
                'priority' => Task::PRIORITY_HIGH,
                'assigned_to' => $owner->id,
                'due_date' => '2026-07-17',
            ])
            ->assertRedirect(route('projects.tasks.show', [$project, $task]));

        $task->refresh();
        $this->assertSame('更新したTask', $task->title);
        $this->assertSame(Task::STATUS_DONE, $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_view_only_member_can_view_but_cannot_edit_task(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $viewer = User::factory()->create();
        $workspace->users()->attach($viewer->id, ['role' => 'member', 'joined_at' => now()]);
        $this->addMember($project, $viewer, $workspace, ProjectMember::ROLE_VIEWER, ProjectMember::PERMISSION_VIEW);
        $task = $this->createTask($project, $owner);

        $this->actingAs($viewer)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.tasks.show', [$project, $task]))
            ->assertOk()
            ->assertDontSee('編集する');

        $this->actingAs($viewer)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.tasks.edit', [$project, $task]))
            ->assertForbidden();
    }

    public function test_task_from_another_project_returns_not_found(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $otherProject = $this->createProject($owner, $workspace, 'Other Project');
        $task = $this->createTask($otherProject, $owner);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.tasks.show', [$project, $task]))
            ->assertNotFound();
    }

    public function test_project_timeline_is_built_from_existing_project_and_task_data(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $project->update(['start_date' => '2026-07-06']);
        $task = $this->createTask($project, $owner);
        $task->update([
            'due_date' => '2026-07-10',
            'status' => Task::STATUS_DONE,
            'completed_at' => '2026-07-12 17:00:00',
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('プロジェクトタイムライン')
            ->assertSee('Projectを開始')
            ->assertSee($task->title)
            ->assertSee('2日遅れて完了しました。');
    }

    private function createProjectOwner(): array
    {
        $owner = User::factory()->create();
        $organization = Organization::create(['name' => 'Task Org', 'slug' => 'task-org']);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Task Workspace',
            'slug' => 'task-workspace',
        ]);
        $organization->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $project = $this->createProject($owner, $workspace, 'Task Project');

        return [$owner, $workspace, $project];
    }

    private function createProject(User $owner, Workspace $workspace, string $name): Project
    {
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => $name,
            'status' => Project::STATUS_ACTIVE,
        ]);
        $this->addMember($project, $owner, $workspace, ProjectMember::ROLE_OWNER, ProjectMember::PERMISSION_ADMIN);

        return $project;
    }

    private function addMember(Project $project, User $user, Workspace $workspace, string $role, string $permission): void
    {
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => $role,
            'permission_level' => $permission,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
    }

    private function createTask(Project $project, User $owner): Task
    {
        return Task::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => '編集対象Task',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_NORMAL,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }
}
