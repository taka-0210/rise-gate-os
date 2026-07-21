<?php

namespace Tests\Feature;

use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadmapFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_admin_can_create_roadmap_as_optional_future_path(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = $this->createProjectWithOwner($owner, $workspace);

        $response = $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.roadmaps.store', $project), [
                'title' => '運用できるOSへ',
                'purpose' => 'Projectが目指す未来への道筋を育てる。',
                'status' => Roadmap::STATUS_ACTIVE,
            ]);

        $response->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('roadmaps', [
            'project_id' => $project->id,
            'title' => '運用できるOSへ',
            'purpose' => 'Projectが目指す未来への道筋を育てる。',
            'created_by' => $owner->id,
        ]);
    }

    public function test_existing_improvement_can_be_added_to_and_removed_from_roadmap(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = $this->createProjectWithOwner($owner, $workspace);
        $roadmap = $this->createRoadmap($project, $owner);
        $improvement = $this->createImprovement($project, $owner, 'Roadmapに追加する改善');

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.improvements.roadmap.assign', [$project, $improvement]), [
                'roadmap_id' => $roadmap->id,
            ])
            ->assertRedirect(route('projects.show', $project));

        $improvement->refresh();
        $this->assertSame($roadmap->id, $improvement->roadmap_id);
        $this->assertSame(1, $improvement->roadmap_sort_order);

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.legacy', $project))
            ->assertOk()
            ->assertSee('Roadmapは、このProjectが目指す未来へ向かうテーマです。')
            ->assertSee('Roadmapに追加する改善')
            ->assertSee('1件中0件が前へ進みました。');

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->delete(route('projects.improvements.roadmap.remove', [$project, $improvement]))
            ->assertRedirect(route('projects.show', $project));

        $improvement->refresh();
        $this->assertNull($improvement->roadmap_id);
        $this->assertNull($improvement->roadmap_sort_order);
    }

    public function test_project_admin_can_choose_new_roadmap_theme_position(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = $this->createProjectWithOwner($owner, $workspace);
        $first = $this->createRoadmap($project, $owner, ['title' => '最初のテーマ', 'sort_order' => 1]);
        $second = $this->createRoadmap($project, $owner, ['title' => '二番目のテーマ', 'sort_order' => 2]);
        $third = $this->createRoadmap($project, $owner, ['title' => '三番目のテーマ', 'sort_order' => 3]);

        $this
            ->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.roadmaps.store', $project), [
                'title' => '差し込むテーマ',
                'purpose' => '必要な位置にロードマップテーマを置く。',
                'status' => Roadmap::STATUS_ACTIVE,
                'position_after_roadmap_id' => $second->id,
            ])
            ->assertRedirect(route('projects.show', $project));

        $this->assertSame(
            ['最初のテーマ', '二番目のテーマ', '差し込むテーマ', '三番目のテーマ'],
            $project->roadmaps()->orderBy('sort_order')->orderBy('id')->pluck('title')->all()
        );

        $this->assertSame(1, $first->refresh()->sort_order);
        $this->assertSame(2, $second->refresh()->sort_order);
        $this->assertSame(4, $third->refresh()->sort_order);
        $this->assertDatabaseHas('roadmaps', [
            'project_id' => $project->id,
            'title' => '差し込むテーマ',
            'sort_order' => 3,
        ]);
    }

    public function test_project_editor_can_set_and_update_roadmap_schedule(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = $this->createProjectWithOwner($owner, $workspace);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.roadmaps.store', $project), [
                'title' => '公開までのRoadmap',
                'purpose' => '公開できる状態へ進める',
                'status' => Roadmap::STATUS_ACTIVE,
                'planned_start_date' => '2026-07-01',
                'target_date' => '2026-07-17',
            ])
            ->assertRedirect(route('projects.show', $project));

        $roadmap = Roadmap::where('project_id', $project->id)->where('title', '公開までのRoadmap')->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.roadmaps.edit', [$project, $roadmap]))
            ->assertOk()
            ->assertSee('開始予定日')
            ->assertSee('到達予定日')
            ->assertSee('実際の到達日');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('projects.roadmaps.update', [$project, $roadmap]), [
                'title' => '公開までのRoadmap',
                'purpose' => '公開できる状態へ進める',
                'status' => Roadmap::STATUS_COMPLETED,
                'planned_start_date' => '2026-07-01',
                'target_date' => '2026-07-17',
                'reached_at' => '2026-07-18',
            ])
            ->assertRedirect(route('projects.show', $project));

        $roadmap->refresh();
        $this->assertSame('2026-07-01', $roadmap->planned_start_date->format('Y-m-d'));
        $this->assertSame('2026-07-17', $roadmap->target_date->format('Y-m-d'));
        $this->assertSame('2026-07-18', $roadmap->reached_at->format('Y-m-d'));
        $this->assertSame(Roadmap::STATUS_ACTIVE, $roadmap->status, '進行状況はフォームから手動変更しない');
    }

    public function test_view_only_project_member_cannot_create_or_assign_roadmap(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        $project = $this->createProjectWithOwner($owner, $workspace);
        $this->addProjectMember($project, $viewer, $viewerWorkspace, ProjectMember::ROLE_VIEWER, ProjectMember::PERMISSION_VIEW);
        $roadmap = $this->createRoadmap($project, $owner);
        $improvement = $this->createImprovement($project, $owner, '守られる改善');

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->post(route('projects.roadmaps.store', $project), [
                'title' => '作成できないRoadmap',
                'status' => Roadmap::STATUS_ACTIVE,
            ])
            ->assertForbidden();

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->post(route('projects.improvements.roadmap.assign', [$project, $improvement]), [
                'roadmap_id' => $roadmap->id,
            ])
            ->assertForbidden();
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
            'name' => 'Roadmap Project',
            'status' => Project::STATUS_ACTIVE,
            'priority' => Project::PRIORITY_NORMAL,
        ]);

        $this->addProjectMember($project, $owner, $workspace, ProjectMember::ROLE_OWNER, ProjectMember::PERMISSION_ADMIN);

        return $project;
    }

    private function createRoadmap(Project $project, User $owner, array $attributes = []): Roadmap
    {
        return Roadmap::create($attributes + [
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => '未来への道筋',
            'purpose' => 'Projectが目指す未来を整理する。',
            'status' => Roadmap::STATUS_ACTIVE,
            'created_by' => $owner->id,
        ]);
    }

    private function createImprovement(Project $project, User $owner, string $title): Improvement
    {
        return Improvement::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'title' => $title,
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
