<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Improvement;
use App\Models\Task;
use App\Models\Roadmap;
use App\Models\Client;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_view_cross_project_schedule_and_overlap(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $first = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => '給与計算システム',
            'start_date' => '2026-08-01',
            'due_date' => '2026-08-20',
        ]);
        $second = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'SNS投稿管理システム',
            'start_date' => '2026-08-15',
            'due_date' => '2026-08-31',
        ]);
        $unscheduled = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => '日程未設定プロジェクト',
        ]);
        foreach ([$first, $second, $unscheduled] as $project) {
            $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');
        }

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->get(route('projects.schedule'))
            ->assertOk()
            ->assertSee('全体スケジュール')
            ->assertSee('給与計算システム')
            ->assertSee('SNS投稿管理システム')
            ->assertSee('1件と重複')
            ->assertSee('日程未設定プロジェクト')
            ->assertSee(route('projects.show', ['project' => $first, 'view' => 'time']), false);
    }

    public function test_user_can_create_project_in_current_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Rise Gate Client']);

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post('/projects', [
                'client_id' => $client->id,
                'name' => 'Website Improvement Project',
                'code' => 'RG-001',
                'summary' => 'The first project centered on improvement.',
                'current_state' => 'Orders are managed in separate spreadsheets.',
                'desired_future_state' => 'Everyone can see and process the same order information.',
                'status' => 'active',
                'priority' => 'high',
                'start_date' => '2026-07-13',
                'due_date' => '2026-08-13',
            ]);

        $project = Project::firstOrFail();

        $response->assertRedirect(route('projects.show', $project));
        $this->assertDatabaseHas('roadmaps', [
            'project_id' => $project->id,
            'title' => 'プロジェクトを前に進める',
            'sort_order' => 1,
        ]);
        $this->assertSame($workspace->id, $project->owning_workspace_id);
        $this->assertSame($workspace->id, $project->billing_workspace_id);
        $this->assertSame($workspace->organization_id, $project->organization_id);
        $this->assertSame($user->id, $project->owner_user_id);
        $this->assertSame('Orders are managed in separate spreadsheets.', $project->current_state);
        $this->assertSame('Everyone can see and process the same order information.', $project->desired_future_state);
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => 'owner',
            'permission_level' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_user_can_create_blank_project_for_manual_or_ai_planning(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create([
            'organization_id' => $workspace->organization_id,
            'workspace_id' => $workspace->id,
            'name' => 'AI Planning Client',
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.store'), [
                'client_id' => $client->id,
                'name' => 'AIと設計するProject',
                'status' => Project::STATUS_DRAFT,
                'priority' => Project::PRIORITY_NORMAL,
                'starter_mode' => 'blank',
            ])
            ->assertRedirect();

        $project = Project::where('name', 'AIと設計するProject')->firstOrFail();
        $this->assertSame(0, $project->roadmaps()->count());
        $this->assertSame(0, $project->improvements()->count());
        $this->assertSame(0, $project->tasks()->count());
    }

    public function test_project_list_only_shows_joined_projects_for_current_workspace(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other@example.com');

        $visibleProject = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'Current Workspace Project',
        ]);
        $this->addProjectMember($visibleProject, $user, $workspace, 'owner', 'admin');

        $hiddenProject = Project::create([
            'organization_id' => $otherWorkspace->organization_id,
            'owning_workspace_id' => $otherWorkspace->id,
            'billing_workspace_id' => $otherWorkspace->id,
            'owner_user_id' => $otherUser->id,
            'name' => 'Other Workspace Project',
        ]);
        $this->addProjectMember($hiddenProject, $otherUser, $otherWorkspace, 'owner', 'admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get('/projects');

        $response->assertOk();
        $response->assertSee('Current Workspace Project');
        $response->assertDontSee('Other Workspace Project');
    }

    public function test_user_cannot_view_project_without_project_membership(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other@example.com');

        $project = Project::create([
            'organization_id' => $otherWorkspace->organization_id,
            'owning_workspace_id' => $otherWorkspace->id,
            'billing_workspace_id' => $otherWorkspace->id,
            'owner_user_id' => $otherUser->id,
            'name' => 'Private Project',
        ]);
        $this->addProjectMember($project, $otherUser, $otherWorkspace, 'owner', 'admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.show', $project));

        $response->assertForbidden();
    }

    public function test_project_admin_can_update_project(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $client = Client::create(['organization_id' => $workspace->organization_id, 'workspace_id' => $workspace->id, 'name' => 'Update Client']);
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'Original Project',
            'status' => 'active',
            'priority' => 'normal',
        ]);
        $this->addProjectMember($project, $user, $workspace, 'owner', 'admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('projects.update', $project), [
                'client_id' => $client->id,
                'name' => 'Updated Project',
                'code' => 'UP-001',
                'summary' => 'Updated summary for operation.',
                'current_state' => 'Current work is fragmented.',
                'desired_future_state' => 'The team works from one shared flow.',
                'status' => 'on_hold',
                'priority' => 'high',
                'start_date' => '2026-07-14',
                'due_date' => '2026-08-14',
            ]);

        $response->assertRedirect(route('projects.show', $project));
        $project->refresh();
        $this->assertSame('Updated Project', $project->name);
        $this->assertSame('UP-001', $project->code);
        $this->assertSame('on_hold', $project->status);
        $this->assertSame('high', $project->priority);
        $this->assertSame('Current work is fragmented.', $project->current_state);
        $this->assertSame('The team works from one shared flow.', $project->desired_future_state);
    }

    public function test_view_only_project_member_cannot_update_project(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$viewer, $viewerWorkspace] = $this->createWorkspaceOwner('Viewer Org', 'Viewer Workspace', 'viewer@example.com');
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Protected Project',
            'status' => 'active',
            'priority' => 'normal',
        ]);
        $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');
        $this->addProjectMember($project, $viewer, $viewerWorkspace, 'viewer', 'view');

        $this
            ->actingAs($viewer)
            ->withSession(['current_workspace_id' => $viewerWorkspace->id])
            ->put(route('projects.update', $project), [
                'client_id' => null,
                'name' => 'Should Not Update',
                'status' => 'active',
                'priority' => 'normal',
            ])
            ->assertForbidden();
    }

    public function test_workspace_owner_can_move_project_and_all_children_to_another_managed_workspace(): void
    {
        [$owner, $sourceWorkspace] = $this->createWorkspaceOwner();
        $destinationOrganization = Organization::create(['name' => 'Destination Org', 'slug' => 'destination-org']);
        $destinationWorkspace = Workspace::create([
            'organization_id' => $destinationOrganization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Destination Workspace',
            'slug' => 'destination-workspace',
        ]);
        $destinationOrganization->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $destinationWorkspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $destinationClient = Client::create([
            'organization_id' => $destinationOrganization->id,
            'workspace_id' => $destinationWorkspace->id,
            'name' => 'Destination Client',
        ]);
        $project = Project::create([
            'organization_id' => $sourceWorkspace->organization_id,
            'owning_workspace_id' => $sourceWorkspace->id,
            'billing_workspace_id' => $sourceWorkspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Movable Project',
        ]);
        $this->addProjectMember($project, $owner, $sourceWorkspace, 'owner', 'admin');
        $improvement = Improvement::create([
            'organization_id' => $sourceWorkspace->organization_id,
            'workspace_id' => $sourceWorkspace->id,
            'project_id' => $project->id,
            'title' => 'Move Improvement',
        ]);
        $task = Task::create([
            'organization_id' => $sourceWorkspace->organization_id,
            'workspace_id' => $sourceWorkspace->id,
            'project_id' => $project->id,
            'title' => 'Move Task',
        ]);
        $roadmap = Roadmap::create([
            'organization_id' => $sourceWorkspace->organization_id,
            'workspace_id' => $sourceWorkspace->id,
            'project_id' => $project->id,
            'title' => 'Move Roadmap',
        ]);

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $sourceWorkspace->id])
            ->post(route('projects.move', $project), [
                'destination_workspace_id' => $destinationWorkspace->id,
                'destination_client_id' => $destinationClient->id,
            ])
            ->assertRedirect(route('projects.show', $project))
            ->assertSessionHas('current_workspace_id', $destinationWorkspace->id);

        $project->refresh();
        $this->assertSame($destinationWorkspace->id, $project->owning_workspace_id);
        $this->assertSame($destinationWorkspace->id, $project->billing_workspace_id);
        $this->assertSame($destinationOrganization->id, $project->organization_id);
        $this->assertSame($destinationClient->id, $project->client_id);
        $this->assertSame($destinationWorkspace->id, $improvement->fresh()->workspace_id);
        $this->assertSame($destinationWorkspace->id, $task->fresh()->workspace_id);
        $this->assertSame($destinationWorkspace->id, $roadmap->fresh()->workspace_id);
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'user_id' => $owner->id, 'workspace_id' => $destinationWorkspace->id]);
    }

    public function test_project_cannot_be_moved_to_workspace_where_user_is_only_a_member(): void
    {
        [$owner, $sourceWorkspace] = $this->createWorkspaceOwner();
        [, $destinationWorkspace] = $this->createWorkspaceOwner('Other Org', 'Other Workspace', 'other-owner@example.com');
        $destinationWorkspace->organization->users()->attach($owner->id, ['role' => 'member', 'joined_at' => now()]);
        $destinationWorkspace->users()->attach($owner->id, ['role' => 'member', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $sourceWorkspace->organization_id,
            'owning_workspace_id' => $sourceWorkspace->id,
            'billing_workspace_id' => $sourceWorkspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Protected Project',
        ]);
        $this->addProjectMember($project, $owner, $sourceWorkspace, 'owner', 'admin');

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $sourceWorkspace->id])
            ->post(route('projects.move', $project), ['destination_workspace_id' => $destinationWorkspace->id, 'destination_client_id' => 999])
            ->assertForbidden();

        $this->assertSame($sourceWorkspace->id, $project->fresh()->owning_workspace_id);
    }

    public function test_workspace_owner_can_soft_delete_project_with_login_password(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Delete Project',
        ]);
        $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->delete(route('projects.destroy', $project), ['delete_password' => 'password'])
            ->assertRedirect(route('projects.index'));

        $this->assertSoftDeleted('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'user_id' => $owner->id]);
    }

    public function test_project_editor_can_save_a_private_local_folder_setting(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Local Files Project',
        ]);
        $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.edit', $project))
            ->assertOk()
            ->assertSee('このPCのローカルフォルダ')
            ->assertSee(route('projects.local-connection.store', $project), false);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.local-connection.store', $project), [
                'directory_name' => 'prohit-okinawa',
                'local_path' => 'C:\\xampp\\htdocs\\prohit-okinawa',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('project_local_connections', [
            'project_id' => $project->id,
            'user_id' => $owner->id,
            'directory_name' => 'prohit-okinawa',
            'status' => 'configured',
        ]);
    }

    public function test_project_is_not_deleted_with_wrong_password(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Protected From Wrong Password',
        ]);
        $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->delete(route('projects.destroy', $project), ['delete_password' => 'wrong-password'])
            ->assertSessionHasErrors('delete_password');

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
    }

    public function test_project_editor_cannot_delete_project(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner();
        [$editor, $editorWorkspace] = $this->createWorkspaceOwner('Editor Org', 'Editor Workspace', 'editor@example.com');
        $project = Project::create([
            'organization_id' => $workspace->organization_id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'name' => 'Owner Only Delete',
        ]);
        $this->addProjectMember($project, $owner, $workspace, 'owner', 'admin');
        $this->addProjectMember($project, $editor, $editorWorkspace, 'coder', 'edit');

        $this->actingAs($editor)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $editorWorkspace->id])
            ->delete(route('projects.destroy', $project), ['delete_password' => 'password'])
            ->assertForbidden();

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'deleted_at' => null]);
    }
    protected function createWorkspaceOwner(
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

        $organization->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
        $workspace->users()->attach($user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return [$user, $workspace];
    }

    protected function addProjectMember(Project $project, User $user, Workspace $workspace, string $role, string $permission): ProjectMember
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

