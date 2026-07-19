<?php

namespace Tests\Feature;

use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ScheduleIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_due_date_must_be_within_parent_improvement_period(): void
    {
        [$user, $workspace, $project, $roadmap, $improvement] = $this->scheduledProject();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.tasks.store', $project), [
                'improvement_id' => $improvement->id,
                'title' => '期間外のタスク',
                'status' => Task::STATUS_TODO,
                'priority' => Task::PRIORITY_NORMAL,
                'due_date' => '2026-08-20',
            ])
            ->assertSessionHasErrors('due_date');

        $this->assertDatabaseMissing('tasks', ['title' => '期間外のタスク']);
    }

    public function test_existing_schedule_is_classified_as_invalid_or_missing(): void
    {
        [$user, $workspace, $project, $roadmap, $improvement] = $this->scheduledProject();

        Task::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'improvement_id' => $improvement->id,
            'title' => '既存の期間外タスク',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_NORMAL,
            'due_date' => '2026-08-20',
            'created_by' => $user->id,
        ]);

        $result = app(ScheduleIntegrityService::class)->inspect($project->fresh());
        $this->assertSame(ScheduleIntegrityService::STATUS_INVALID, $result['status']);
        $this->assertSame('日程要再設定：残り1件', $result['label']);
        $this->assertSame(1, $result['remaining_count']);
        $this->assertTrue($result['invalid']->contains(fn (string $issue) => str_contains($issue, '既存の期間外タスク')));
        $this->assertSame(1, $result['counts']['invalid']['task']);

        $improvement->update(['planned_start_date' => null, 'target_date' => null]);
        $result = app(ScheduleIntegrityService::class)->inspect($project->fresh());
        $this->assertSame(ScheduleIntegrityService::STATUS_MISSING, $result['status']);
        $this->assertSame('日程未設定：残り1件', $result['label']);
        $this->assertSame(1, $result['counts']['missing']['improvement']);
        $this->assertSame(1, $result['counts']['unverifiable']['task']);
    }

    public function test_timeline_drag_endpoint_can_correct_an_existing_invalid_task(): void
    {
        [$user, $workspace, $project, $roadmap, $improvement] = $this->scheduledProject();
        $task = Task::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'improvement_id' => $improvement->id,
            'title' => 'ドラッグで直すタスク',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_NORMAL,
            'due_date' => '2026-08-20',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->patchJson(route('projects.timeline.update', [$project, 'task', $task->id]), [
                'end_date' => '2026-08-10',
            ])
            ->assertOk()
            ->assertJsonPath('integrity.status', ScheduleIntegrityService::STATUS_OK);

        $this->assertSame('2026-08-10', $task->fresh()->due_date->toDateString());
    }

    public function test_moving_a_roadmap_moves_its_descendants_and_keeps_out_of_range_dates_visible(): void
    {
        [$user, $workspace, $project, $roadmap, $improvement] = $this->scheduledProject();
        $task = Task::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'improvement_id' => $improvement->id,
            'title' => '移動対象タスク',
            'status' => Task::STATUS_TODO,
            'priority' => Task::PRIORITY_NORMAL,
            'due_date' => '2026-08-10',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->patchJson(route('projects.timeline.update', [$project, 'roadmap', $roadmap->id]), [
                'start_date' => '2026-08-28',
                'end_date' => '2026-09-06',
                'cascade_move' => true,
            ])
            ->assertOk()
            ->assertJsonPath('integrity.status', ScheduleIntegrityService::STATUS_INVALID)
            ->assertJsonPath('integrity.counts.invalid.roadmap', 1)
            ->assertJsonPath('integrity.counts.invalid.improvement', 0)
            ->assertJsonPath('integrity.counts.invalid.task', 0);

        $this->assertSame('2026-08-28', $roadmap->fresh()->planned_start_date->toDateString());
        $this->assertSame('2026-08-28', $improvement->fresh()->planned_start_date->toDateString());
        $this->assertSame('2026-09-01', $improvement->fresh()->target_date->toDateString());
        $this->assertSame('2026-08-30', $task->fresh()->due_date->toDateString());
    }

    private function scheduledProject(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => '日程管理', 'slug' => 'schedule-'.uniqid()]);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'name' => '社内WS', 'slug' => 'internal-'.uniqid()]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => '給与計算システム',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_NORMAL,
            'start_date' => '2026-08-01',
            'due_date' => '2026-08-31',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'invited_by' => $user->id,
            'invited_at' => now(),
            'accepted_at' => now(),
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $roadmap = Roadmap::create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => '入力と計算',
            'planned_start_date' => '2026-08-08',
            'target_date' => '2026-08-17',
            'status' => Roadmap::STATUS_ACTIVE,
            'sort_order' => 1,
            'created_by' => $user->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'roadmap_sort_order' => 1,
            'title' => '勤怠データ取込',
            'planned_start_date' => '2026-08-08',
            'target_date' => '2026-08-12',
            'status' => Improvement::STATUS_PLANNED,
            'visibility' => Improvement::VISIBILITY_INTERNAL,
            'proposed_by' => $user->id,
        ]);

        return [$user, $workspace, $project, $roadmap, $improvement];
    }
}
