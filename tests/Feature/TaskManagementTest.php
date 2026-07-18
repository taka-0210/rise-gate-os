<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Improvement;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
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
        $initiative = $task->improvement;

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.tasks.show', [$project, $task]))
            ->assertOk()
            ->assertSee($task->title)
            ->assertSee('編集する');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('projects.tasks.update', [$project, $task]), [
                'improvement_id' => $initiative->id,
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

    public function test_alternative_work_view_shows_current_layer_and_existing_project_data(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $roadmap = Roadmap::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '表示範囲と権限の設計',
            'status' => Roadmap::STATUS_ACTIVE,
            'sort_order' => 1,
            'created_by' => $owner->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'roadmap_sort_order' => 1,
            'title' => '権限を整理する',
            'status' => Improvement::STATUS_PROPOSED,
            'visibility' => Improvement::VISIBILITY_INTERNAL,
            'proposed_by' => $owner->id,
        ]);
        $task = $this->createTask($project, $owner);
        $task->update(['improvement_id' => $improvement->id]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('フォーカスレイヤー')
            ->assertSee('PROJECT・何を実現するか')
            ->assertSee('ROADMAP・実現までの道筋')
            ->assertSee('取り組み・道筋を前へ進める')
            ->assertSee('TASK・いま何をするか')
            ->assertSee('data-focus-roadmap="'.$roadmap->id.'"', false)
            ->assertSee('data-focus-improvement="'.$improvement->id.'"', false)
            ->assertSee('data-focus-task="'.$task->id.'"', false)
            ->assertSee('いま行うこと')
            ->assertSee($task->title)
            ->assertSee('管理詳細を見る');
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
        $initiative = Improvement::firstOrCreate(
            ['project_id' => $project->id, 'roadmap_id' => $roadmap->id, 'title' => '進めるための具体的な動き'],
            [
                'organization_id' => $project->organization_id,
                'workspace_id' => $project->owning_workspace_id,
                'roadmap_sort_order' => 1,
                'status' => Improvement::STATUS_PLANNED,
                'visibility' => Improvement::VISIBILITY_INTERNAL,
                'proposed_by' => $owner->id,
            ]
        );

        return Task::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'improvement_id' => $initiative->id,
            'title' => '編集対象Task',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_NORMAL,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }
}
