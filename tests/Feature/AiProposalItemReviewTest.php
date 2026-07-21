<?php

namespace Tests\Feature;

use App\Models\AiProposal;
use App\Models\AiProposalItem;
use App\Models\AiProposalItemReview;
use App\Models\AiRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiProposalItemReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_form_is_rendered_inside_the_proposed_item_outline(): void
    {
        [$user, $workspace, $project, $proposal] = $this->fixture();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()
            ->assertSeeInOrder(['注文一覧を作る', '編集', 'コメント・修正指示', 'このロードマップを一括保存'])
            ->assertSee('全体・項目別の指示でAIに再提案を依頼');
    }

    public function test_roadmap_editor_saves_multiple_item_reviews_at_once(): void
    {
        [$user, $workspace, $project, $proposal, $task] = $this->fixture();
        $secondTask = $proposal->items()->create([
            'operation' => 'create',
            'entity_type' => 'task',
            'attributes' => ['title' => '発送一覧を作る'],
            'validation_status' => 'valid',
            'sort_order' => 20,
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.roadmap-reviews.store', [$project, $proposal]), [
                'reviews' => [
                    $task->id => ['action' => 'revise', 'comment' => '名称を変更する'],
                    $secondTask->id => ['action' => 'exclude', 'comment' => 'このタスクは不要'],
                ],
            ])->assertRedirect();

        $this->assertDatabaseHas('ai_proposal_item_reviews', ['ai_proposal_item_id' => $task->id, 'action' => 'revise']);
        $this->assertDatabaseHas('ai_proposal_item_reviews', ['ai_proposal_item_id' => $secondTask->id, 'action' => 'exclude']);
    }

    public function test_member_can_save_item_instruction_and_unresolved_instruction_blocks_apply(): void
    {
        [$user, $workspace, $project, $proposal, $item] = $this->fixture();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.items.review.store', [$project, $proposal, $item]), [
                'action' => AiProposalItemReview::ACTION_REVISE,
                'comment' => '名称を「受注一覧を作る」に変更する',
            ])->assertRedirect();

        $this->assertDatabaseHas('ai_proposal_item_reviews', [
            'ai_proposal_item_id' => $item->id,
            'action' => 'revise',
            'comment' => '名称を「受注一覧を作る」に変更する',
            'resolved_at' => null,
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.apply', [$project, $proposal]))
            ->assertSessionHasErrors('reviews');
    }

    public function test_merge_requires_another_item_of_the_same_type(): void
    {
        [$user, $workspace, $project, $proposal, $item] = $this->fixture();
        $roadmap = $proposal->items()->create([
            'operation' => 'create',
            'entity_type' => 'roadmap',
            'attributes' => ['title' => '別の種類'],
            'validation_status' => 'valid',
            'sort_order' => 20,
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.items.review.store', [$project, $proposal, $item]), [
                'action' => AiProposalItemReview::ACTION_MERGE,
                'comment' => 'この項目と一緒にする',
                'merge_target_item_id' => $roadmap->id,
            ])->assertSessionHasErrors('merge_target_item_id');
    }

    public function test_review_instructions_create_a_new_pending_ai_request(): void
    {
        [$user, $workspace, $project, $proposal, $item] = $this->fixture();
        $item->review()->create([
            'reviewed_by' => $user->id,
            'action' => AiProposalItemReview::ACTION_EXCLUDE,
            'comment' => 'このタスクは不要',
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.request-revision', [$project, $proposal]))
            ->assertRedirect()
            ->assertSessionHas('ai_request_copy_text');

        $aiRequest = AiRequest::latest('id')->firstOrFail();
        $this->assertSame(AiRequest::STATUS_PENDING, $aiRequest->status);
        $this->assertStringContainsString('このタスクは不要', $aiRequest->instructions);
        $this->assertStringContainsString('提案から外す', $aiRequest->instructions);
        $this->assertStringContainsString($proposal->public_id, $aiRequest->instructions);
    }

    public function test_overall_feedback_can_request_revision_without_item_reviews(): void
    {
        [$user, $workspace, $project, $proposal] = $this->fixture();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.request-revision', [$project, $proposal]), [
                'overall_feedback' => '管理画面の作り込みについて、取り組みとタスクを追加してください。',
            ])->assertRedirect()
            ->assertSessionHas('ai_request_copy_text');

        $aiRequest = AiRequest::latest('id')->firstOrFail();
        $this->assertSame(AiRequest::STATUS_PENDING, $aiRequest->status);
        $this->assertStringContainsString('【提案全体への追加指示】', $aiRequest->instructions);
        $this->assertStringContainsString('管理画面の作り込みについて、取り組みとタスクを追加してください。', $aiRequest->instructions);
    }

    public function test_revision_requires_overall_feedback_or_an_item_review(): void
    {
        [$user, $workspace, $project, $proposal] = $this->fixture();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.request-revision', [$project, $proposal]))
            ->assertSessionHasErrors('overall_feedback');

        $this->assertDatabaseCount('ai_requests', 0);
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Rise Gate', 'slug' => 'review-org']);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'レビューWorkspace',
            'slug' => 'review-workspace',
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => '受注管理システム',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_OWNER,
            'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $proposal = AiProposal::create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'source' => 'codex',
            'idempotency_key' => 'item-review-test',
            'title' => '受注管理計画',
            'status' => AiProposal::STATUS_PENDING,
        ]);
        $item = AiProposalItem::create([
            'ai_proposal_id' => $proposal->id,
            'operation' => 'create',
            'entity_type' => 'task',
            'attributes' => ['title' => '注文一覧を作る'],
            'validation_status' => 'valid',
            'sort_order' => 10,
        ]);

        return [$user, $workspace, $project, $proposal, $item];
    }
}
