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

    public function test_unscheduled_project_can_set_its_period_from_the_time_view(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $project->update(['duration_days' => 30]);
        $task = $this->createTask($project, $owner);
        $task->improvement->roadmap->update([
            'planned_start_date' => '2026-08-03',
            'target_date' => '2026-08-28',
        ]);
        $task->improvement->update([
            'planned_start_date' => '2026-08-05',
            'target_date' => '2026-08-20',
        ]);
        $task->update([
            'planned_start_date' => '2026-08-07',
            'due_date' => '2026-08-10',
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time']))
            ->assertOk()
            ->assertSee('Project期間を設定')
            ->assertSee('id="project-schedule-setup"', false)
            ->assertSee('name="start_date" type="date" value="2026-08-03"', false)
            ->assertDontSee('name="end_date"', false)
            ->assertSee('1日目');
    }

    public function test_project_editor_can_view_edit_and_update_task(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $task = $this->createTask($project, $owner);
        $initiative = $task->improvement;
        $initiative->update(['planned_effort_days' => 2.5]);

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

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('2.5/2.5')
            ->assertSee('完了／タスク')
            ->assertSee('進捗／工数');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('1/1')
            ->assertSee('2.5/2.5')
            ->assertSee('完了／タスク')
            ->assertSee('進捗／工数');
    }

    public function test_relative_timeline_periods_can_be_edited_within_their_parent(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $project->update(['duration_days' => 30]);
        $task = $this->createTask($project, $owner);
        $roadmap = $task->improvement->roadmap;

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->patchJson(route('projects.timeline.update', [$project, 'roadmap', $roadmap->id]), [
                'start_day' => 2,
                'end_day' => 20,
            ])->assertOk();

        $this->patchJson(route('projects.timeline.update', [$project, 'improvement', $task->improvement_id]), [
            'start_day' => 1,
            'end_day' => 10,
        ])->assertUnprocessable();

        $this->patchJson(route('projects.timeline.update', [$project, 'improvement', $task->improvement_id]), [
            'start_day' => 3,
            'end_day' => 10,
        ])->assertOk();

        $this->assertSame(2, $roadmap->fresh()->planned_start_day);
        $this->assertSame(20, $roadmap->fresh()->target_day);
        $this->assertSame(3, $task->improvement->fresh()->planned_start_day);
    }

    public function test_improvement_efforts_can_be_entered_together_from_time_view(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $task = $this->createTask($project, $owner);
        $improvement = $task->improvement;
        $improvement->update(['planned_effort_days' => 4.5]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time']))
            ->assertOk()
            ->assertSee('工数を一括入力')
            ->assertSee('4.5人日')
            ->assertSee('現在の予定工数')
            ->assertSee('data-effort-editor', false)
            ->assertSee('name="efforts['.$improvement->id.']"', false)
            ->assertSee('未設定のみ表示');

        $this->patch(route('projects.improvement-efforts.update', $project), [
            'efforts' => [$improvement->id => 4.5],
        ])->assertRedirect(route('projects.show', ['project' => $project, 'view' => 'time', 'effort_editor' => 1]));

        $this->assertSame('4.50', $improvement->fresh()->planned_effort_days);
    }

    public function test_manual_plan_management_is_available_from_the_project_and_deletes_safely(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $task = $this->createTask($project, $owner);
        $improvement = $task->improvement;
        $roadmap = $improvement->roadmap;

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee(route('projects.roadmaps.create', $project), false)
            ->assertSee(route('projects.tasks.create', [$project, 'improvement' => $improvement->id]), false);

        $this->delete(route('projects.roadmaps.destroy', [$project, $roadmap]))
            ->assertSessionHasErrors('delete');
        $this->delete(route('projects.improvements.destroy', [$project, $improvement]))
            ->assertSessionHasErrors('delete');

        $this->delete(route('projects.tasks.destroy', [$project, $task]))->assertRedirect(route('projects.show', $project));
        $this->delete(route('projects.improvements.destroy', [$project, $improvement]))->assertRedirect(route('projects.show', $project));
        $this->delete(route('projects.roadmaps.destroy', [$project, $roadmap]))->assertRedirect(route('projects.show', $project));

        $this->assertSoftDeleted($task);
        $this->assertSoftDeleted($improvement);
        $this->assertSoftDeleted($roadmap);
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
            ->assertDontSee('配下から自動算出')
            ->assertDontSee('background:repeating-linear-gradient(135deg,#e3a11d', false)
            ->assertSee('.time-legend .is-overdue { background:#c58a22;', false)
            ->assertSeeInOrder([
                'class="schedule-step-guide"',
                'class="time-legend"',
                'class="time-chart-scroll"',
            ], false)
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
            ->assertSee('メンバー・詳細管理');
    }

    public function test_project_has_an_optional_three_pane_workspace(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $task = $this->createTask($project, $owner);
        $task->update(['title' => '中央に表示するタスク']);
        $task->improvement->update(['title' => '商品化の取組み']);
        $roadmap = $task->improvement->roadmap;
        $roadmap->update(['title' => '商品化ロードマップ']);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('3ペイン表示')
            ->assertSee(route('projects.workspace', $project), false);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.workspace', $project))
            ->assertOk()
            ->assertSee('WORK')
            ->assertSee('FILES')
            ->assertSee('prohit-okinawa')
            ->assertSee('ブラウザ')
            ->assertSee('data-file-view="browser"', false)
            ->assertDontSee('data-viewer-tab', false)
            ->assertSee('AI PARTNER')
            ->assertSee('現行表示へ戻る')
            ->assertSee($project->name)
            ->assertSee($roadmap->title)
            ->assertSee($task->improvement->title)
            ->assertSee($task->title)
            ->assertSee('data-pane="explorer"', false)
            ->assertSee('data-explorer-tab="work"', false)
            ->assertSee('data-explorer-tab="files"', false)
            ->assertSee('data-tree-toggle="roadmap-', false)
            ->assertSee('data-tree-toggle="improvement-', false)
            ->assertSee('data-document="task-'.$task->id.'"', false)
            ->assertSee('data-document-panel="task-'.$task->id.'"', false)
            ->assertSee('data-pane="main"', false)
            ->assertSee('data-pane="ai"', false)
            ->assertSee('tree-item--roadmap', false)
            ->assertSee('tree-item--improvement', false)
            ->assertSee('tree-item--task', false);
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
            ->assertSee('<span>取り組み</span>', false)
            ->assertSee('text-align:center', false)
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

    public function test_time_view_and_client_plan_order_improvements_by_relative_start_day(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $project->update(['start_date' => null, 'due_date' => null, 'duration_days' => 30]);
        $task = $this->createTask($project, $owner);
        $roadmap = $task->improvement->roadmap;
        $roadmap->update(['planned_start_day' => 25, 'target_day' => 30]);
        $task->improvement->update([
            'title' => 'General test later',
            'visibility' => Improvement::VISIBILITY_CLIENT,
            'roadmap_sort_order' => 1,
            'planned_start_day' => 27,
            'target_day' => 30,
        ]);
        Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'roadmap_sort_order' => 99,
            'title' => 'Migration first',
            'status' => Improvement::STATUS_PLANNED,
            'visibility' => Improvement::VISIBILITY_CLIENT,
            'planned_start_day' => 25,
            'target_day' => 27,
            'proposed_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time', 'schedule_step' => 'all']))
            ->assertOk()
            ->assertSeeInOrder([
                'data-time-row-title="Migration first"',
                'data-time-row-title="General test later"',
            ], false);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.client-plan', $project))
            ->assertOk()
            ->assertSeeInOrder(['Migration first', 'General test later']);
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
            'current_state' => '注文情報が複数の台帳に分かれている。',
            'desired_future_state' => '注文から発送まで、迷わずひとつの流れで進められる。',
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
        foreach (range(2, 8) as $sortOrder) {
            Roadmap::create([
                'organization_id' => $project->organization_id,
                'workspace_id' => $workspace->id,
                'project_id' => $project->id,
                'title' => '提出用ロードマップ'.$sortOrder,
                'status' => Roadmap::STATUS_DRAFT,
                'sort_order' => $sortOrder,
                'created_by' => $owner->id,
            ]);
        }

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', ['project' => $project, 'view' => 'time']))
            ->assertOk()
            ->assertSee('お客さま提出資料')
            ->assertDontSee('社内用印刷');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.client-plan', $project))
            ->assertOk()
            ->assertSee('プロジェクト実施計画書')
            ->assertSee('提出先株式会社 御中')
            ->assertSee('お客さまへ提出する計画概要')
            ->assertSee('注文情報が複数の台帳に分かれている。')
            ->assertSee('注文から発送まで、迷わずひとつの流れで進められる。')
            ->assertSee($task->improvement->roadmap->title)
            ->assertSee('提出用ロードマップ8')
            ->assertSee('data-overview-count="2"', false)
            ->assertSee('data-overview-count="6"', false)
            ->assertSee($task->improvement->title)
            ->assertSee($task->title)
            ->assertSee('<div class="task-detail">', false)
            ->assertSee('<div class="task-list-title">タスク</div>', false)
            ->assertSee('border-left:5px solid var(--blue)', false)
            ->assertSee('border-radius:0 8px 8px 0', false)
            ->assertSee('border-left:5px solid var(--green)', false)
            ->assertSee('border-left:5px solid var(--red)', false)
            ->assertSee('background:#f8fafb; text-align:center;', false)
            ->assertSee('text-align:left; font-weight:800;', false)
            ->assertSeeInOrder([
                '1. 現状と目指す未来のカタチ',
                '2. プロジェクト概要',
                '3. 取り組み、タスク詳細',
                '4. 全体スケジュール',
            ])
            ->assertSee('印刷・PDF保存')
            ->assertSee('name="show_progress" value="1" >進捗を掲載', false)
            ->assertSee('vendor/pagedjs/paged.polyfill.js', false)
            ->assertSee('size:297mm 210mm', false)
            ->assertSee('.schedule-bar { position:absolute; top:10px; height:14px; min-width:5px; border-radius:3px; }', false)
            ->assertDontSee('@bottom-center', false)
            ->assertSee('preview(source, [pagedStylesheet], output)', false)
            ->assertSee("source.classList.add('preview-fallback')", false);
    }

    public function test_client_plan_uses_relative_days_when_project_has_no_start_date(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();
        $project->update(['start_date' => null, 'due_date' => null, 'duration_days' => 30]);
        $task = $this->createTask($project, $owner);
        $task->improvement->roadmap->update([
            'title' => '1. Current analysis',
            'planned_start_date' => null,
            'target_date' => null,
            'planned_start_day' => 2,
            'target_day' => 20,
        ]);
        $task->improvement->update([
            'visibility' => Improvement::VISIBILITY_CLIENT,
            'planned_start_date' => null,
            'target_date' => null,
            'planned_start_day' => 3,
            'target_day' => 18,
        ]);
        $task->update([
            'planned_start_date' => null,
            'due_date' => null,
            'planned_start_day' => 4,
            'due_day' => 12,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.client-plan', ['project' => $project, 'show_tasks' => 1]))
            ->assertOk()
            ->assertSee('2～20日目')
            ->assertSee('3～18日目')
            ->assertDontSee('4～12日目')
            ->assertDontSee('1. 1. Current analysis')
            ->assertDontSee('ロードマップ 1：1. Current analysis')
            ->assertDontSee('未設定 〜 未設定');
    }

    public function test_internal_note_is_visible_in_project_but_never_in_client_plan(): void
    {
        [$owner, $workspace, $project] = $this->createProjectOwner();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.internal-notes.store', $project), ['body' => '見積条件について社内で再確認する'])
            ->assertRedirect(route('projects.show', $project));

        $this->assertDatabaseHas('project_internal_notes', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'body' => '見積条件について社内で再確認する',
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project))
            ->assertOk()
            ->assertSee('社内非公開エリア')
            ->assertSee('見積条件について社内で再確認する');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.client-plan', $project))
            ->assertOk()
            ->assertDontSee('見積条件について社内で再確認する');
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
