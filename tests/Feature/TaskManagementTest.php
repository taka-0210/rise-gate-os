<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\AiProposal;
use App\Models\Client;
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
        AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'focus-drawer-pending',
            'title' => '確認待ちの提案',
            'status' => AiProposal::STATUS_PENDING,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('フォーカスレイヤー')
            ->assertSee('PROJECT・何を実現するか')
            ->assertSee('ROADMAP・実現までの道筋')
            ->assertSee('取り組み・道筋を前へ進める')
            ->assertSee('TASK・いま何をするか')
            ->assertSee('今日を時間軸に含める')
            ->assertSee('id="time-today-toggle"', false)
            ->assertSee('進行中かつ期限超過')
            ->assertSee('未着手')
            ->assertSee('AIアシスタント')
            ->assertSee('aria-label="承認待ち 1件"', false)
            ->assertSee('id="ai-assistant-drawer"', false)
            ->assertSee('承認待ちのAI提案 1件')
            ->assertSee('確認待ちの提案')
            ->assertSee('内容を確認')
            ->assertSee('AI提案一覧へ')
            ->assertSee('data-focus-roadmap="'.$roadmap->id.'"', false)
            ->assertSee('data-focus-improvement="'.$improvement->id.'"', false)
            ->assertSee('data-focus-task="'.$task->id.'"', false)
            ->assertSee('いま行うこと')
            ->assertSee($task->title)
            ->assertSee('管理詳細を見る');
    }

    public function test_time_view_only_marks_started_overdue_tasks_as_delayed(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $task = $this->createTask($project, $owner);
        $task->update([
            'planned_start_date' => now()->subWeek()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $todoHtml = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time', 'schedule_step' => 'all']))
            ->assertOk()
            ->getContent();
        preg_match('/編集対象Task.*?time-bar is-task ([^"]*)/s', $todoHtml, $todoBar);
        $this->assertStringNotContainsString('is-overdue', $todoBar[1] ?? '');

        $task->update(['status' => Task::STATUS_IN_PROGRESS]);
        $startedHtml = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time', 'schedule_step' => 'all']))
            ->assertOk()
            ->getContent();
        preg_match('/編集対象Task.*?time-bar is-task ([^"]*)/s', $startedHtml, $startedBar);
        $this->assertStringContainsString('is-overdue', $startedBar[1] ?? '');
    }

    public function test_time_view_can_focus_on_future_plan_without_including_today(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $projectStart = now()->addMonths(3)->startOfDay();
        $projectEnd = $projectStart->copy()->addMonth();
        $project->update([
            'start_date' => $projectStart->toDateString(),
            'due_date' => $projectEnd->toDateString(),
        ]);
        $task = $this->createTask($project, $owner);
        $improvementStart = $projectStart->copy()->addDays(7);
        $task->improvement()->update(['planned_start_date' => $improvementStart->toDateString()]);
        $task->update(['due_date' => $projectStart->copy()->addDays(10)->toDateString()]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time', 'include_today' => '0', 'schedule_step' => 'all']))
            ->assertOk()
            ->assertSee('data-axis-start="'.$projectStart->copy()->subDays(2)->toDateString().'"', false)
            ->assertSee('data-axis-end="'.$projectEnd->copy()->addDays(2)->toDateString().'"', false)
            ->assertSee('data-bar-start="'.$improvementStart->toDateString().'"', false)
            ->assertDontSee('<span class="time-today">', false)
            ->assertDontSee('id="time-today-toggle" type="checkbox" checked', false);
    }

    public function test_time_view_orders_roadmaps_by_planned_start_instead_of_registration_order(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        Roadmap::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '後半工程',
            'status' => Roadmap::STATUS_ACTIVE,
            'sort_order' => 1,
            'planned_start_date' => '2026-08-20',
            'target_date' => '2026-08-31',
            'created_by' => $owner->id,
        ]);
        Roadmap::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '最初の工程',
            'status' => Roadmap::STATUS_ACTIVE,
            'sort_order' => 99,
            'planned_start_date' => '2026-08-01',
            'target_date' => '2026-08-10',
            'created_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time', 'include_today' => '0', 'schedule_step' => 'all']))
            ->assertOk()
            ->assertSeeInOrder([
                'data-time-row-title="最初の工程"',
                'data-time-row-title="後半工程"',
            ], false);
    }

    public function test_time_view_has_a_print_layout_with_project_summary_and_counts(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $client = Client::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'name' => '印刷確認クライアント',
        ]);
        $project->update([
            'client_id' => $client->id,
            'summary' => '印刷用のプロジェクト概要です。',
            'start_date' => '2026-08-01',
            'due_date' => '2026-08-31',
        ]);
        $this->createTask($project, $owner);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time', 'print' => 1]))
            ->assertOk()
            ->assertSee('クライアント：印刷確認クライアント')
            ->assertSee('印刷用のプロジェクト概要です。')
            ->assertSee('ロードマップ 1件')
            ->assertSee('取り組み 1件')
            ->assertSee('タスク 1件')
            ->assertSee("window.addEventListener('load', () => window.print()", false);
    }

    public function test_project_member_can_preview_a_client_facing_project_plan(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $client = Client::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'name' => '提出先株式会社',
        ]);
        $project->update([
            'client_id' => $client->id,
            'summary' => 'お客さまへ提出する計画概要',
            'start_date' => '2026-08-01',
            'due_date' => '2026-08-31',
        ]);
        $task = $this->createTask($project, $owner);
        $task->improvement->update([
            'visibility' => Improvement::VISIBILITY_CLIENT,
            'planned_start_date' => '2026-08-02',
            'target_date' => '2026-08-20',
        ]);
        $task->improvement->roadmap->update([
            'planned_start_date' => '2026-08-01',
            'target_date' => '2026-08-25',
        ]);
        $task->update(['planned_start_date' => '2026-08-03', 'due_date' => '2026-08-10']);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.client-plan', $project))
            ->assertOk()
            ->assertSee('プロジェクト実施計画書')
            ->assertSee('提出先株式会社 御中')
            ->assertSee('お客さまへ提出する計画概要')
            ->assertSee($task->improvement->roadmap->title)
            ->assertSee($task->improvement->title)
            ->assertSee($task->title)
            ->assertSee('PDF保存・印刷');
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
