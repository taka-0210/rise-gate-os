<?php

namespace Tests\Feature;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProposalFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_member_can_view_pending_ai_proposal_without_changing_project_data(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = $this->proposal($project, $user);

        $response = $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]));

        $response->assertOk()
            ->assertSee('新しいタスクを登録する')
            ->assertSee('承認待ち')
            ->assertSee('現在は閲覧のみです');
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('ai_proposals', ['id' => $proposal->id, 'status' => 'pending']);
    }

    public function test_ai_proposal_cannot_be_viewed_from_a_different_current_workspace(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = $this->proposal($project, $user);
        $otherWorkspace = Workspace::create([
            'organization_id' => $workspace->organization_id,
            'owner_user_id' => $user->id,
            'name' => 'Client WS',
            'slug' => 'client',
        ]);
        $user->workspaces()->attach($otherWorkspace->id, ['role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertNotFound();
    }

    public function test_idempotency_key_is_unique_within_workspace_and_source(): void
    {
        [$user, , $project] = $this->projectOwner('internal');
        $this->proposal($project, $user);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->proposal($project, $user);
    }

    public function test_project_admin_can_apply_hierarchical_create_proposal_atomically(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'hierarchy-001',
            'title' => '計画一括登録',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $proposal->items()->createMany([
            ['operation' => 'create', 'entity_type' => 'roadmap', 'reference_key' => 'roadmap-1', 'attributes' => ['title' => 'AI Roadmap'], 'sort_order' => 10],
            ['operation' => 'create', 'entity_type' => 'improvement', 'reference_key' => 'improvement-1', 'parent_reference' => 'roadmap-1', 'attributes' => ['title' => 'AI Improvement'], 'sort_order' => 20],
            ['operation' => 'create', 'entity_type' => 'task', 'parent_reference' => 'improvement-1', 'attributes' => ['title' => 'AI Task'], 'sort_order' => 30],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.apply', [$project, $proposal]))
            ->assertRedirect(route('projects.ai-proposals.show', [$project, $proposal]));

        $roadmap = \App\Models\Roadmap::where('title', 'AI Roadmap')->firstOrFail();
        $improvement = \App\Models\Improvement::where('title', 'AI Improvement')->firstOrFail();
        $task = \App\Models\Task::where('title', 'AI Task')->firstOrFail();
        $this->assertSame($roadmap->id, $improvement->roadmap_id);
        $this->assertSame($improvement->id, $task->improvement_id);
        $this->assertSame(AiProposal::STATUS_APPLIED, $proposal->fresh()->status);
    }

    public function test_invalid_proposal_cannot_be_applied(): void
    {
        [$user, $workspace, $project] = $this->projectOwner('internal');
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'invalid-001',
            'title' => '不正な提案',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $proposal->items()->create([
            'operation' => 'create',
            'entity_type' => 'task',
            'attributes' => ['title' => 'Unsafe Task', 'password' => 'secret'],
        ]);

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.apply', [$project, $proposal]))
            ->assertSessionHasErrors('proposal');

        $this->assertDatabaseMissing('tasks', ['title' => 'Unsafe Task']);
        $this->assertSame(AiProposal::STATUS_PENDING, $proposal->fresh()->status);
    }

    private function proposal(Project $project, User $user): AiProposal
    {
        $proposal = AiProposal::create([
            'organization_id' => $project->organization_id,
            'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'request-001',
            'title' => '開発計画の追加提案',
            'summary' => '本データへ反映する前の提案です。',
            'status' => AiProposal::STATUS_PENDING,
            'requested_by' => $user->id,
            'evidence' => ['conversation' => 'ユーザーからの依頼'],
        ]);

        AiProposalItem::create([
            'ai_proposal_id' => $proposal->id,
            'operation' => AiProposalItem::OPERATION_CREATE,
            'entity_type' => 'task',
            'attributes' => ['title' => '新しいタスクを登録する', 'priority' => 'high'],
        ]);

        return $proposal;
    }

    private function projectOwner(string $slug): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'rise-gate-'.$slug]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => '社内WS',
            'slug' => $slug,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'RISE GATE OS',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        return [$user, $workspace, $project];
    }
}
