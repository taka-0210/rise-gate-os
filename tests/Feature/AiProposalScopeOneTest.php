<?php

namespace Tests\Feature;

use App\Exceptions\AiProposalApplyException;
use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\AiProposalApplyAttempt;
use App\Models\AiProposalItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiProposalAuthorization;
use App\Services\AiProposalContract;
use App\Services\AiProposalFactory;
use App\Services\AiProposalScopeOneApplier;
use App\Services\AiProposalValidator;
use App\Services\ProjectPlanSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiProposalScopeOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_contract_is_approved_then_applied_atomically_with_results(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture();
        $proposal = $this->proposal($key, $project, [
            ['operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id, 'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Updated purpose']],
            ['operation' => 'create', 'entity_type' => 'roadmap', 'reference_key' => 'r1', 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Roadmap A', 'purpose' => 'Stage']],
            ['operation' => 'create', 'entity_type' => 'improvement', 'reference_key' => 'i1', 'parent_reference' => 'r1', 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Action Theme A', 'problem' => 'Problem']],
            ['operation' => 'create', 'entity_type' => 'task', 'parent_reference' => 'i1', 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Action A', 'description' => 'Do it']],
        ]);

        $this->approveAs($user, $workspace, $project, $proposal)->assertSessionHasNoErrors();
        $this->assertSame(AiProposal::STATUS_APPROVED, $proposal->fresh()->status);
        $this->assertNull($project->fresh()->summary);
        $this->applyAs($user, $workspace, $project, $proposal)->assertSessionHasNoErrors();

        $this->assertSame('Updated purpose', $project->fresh()->summary);
        $this->assertDatabaseHas('roadmaps', ['project_id' => $project->id, 'title' => 'Roadmap A']);
        $this->assertDatabaseHas('improvements', ['project_id' => $project->id, 'title' => 'Action Theme A']);
        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'title' => 'Action A']);
        $attempt = $proposal->applyAttempts()->with('itemResults')->firstOrFail();
        $this->assertSame(AiProposalApplyAttempt::STATUS_APPLIED, $attempt->status);
        $this->assertSame(4, $attempt->itemResults->count());
        $this->assertTrue($attempt->itemResults->every(fn ($result) => $result->status === 'applied' && $result->applied_entity_public_id));
    }

    public function test_human_edit_after_approval_causes_conflict_without_overwrite(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture();
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'AI value'],
        ]]);
        $this->approveAs($user, $workspace, $project, $proposal)->assertSessionHasNoErrors();
        $project->update(['summary' => 'Human value']);
        $this->applyAs($user, $workspace, $project, $proposal)->assertSessionHasErrors('proposal');
        $this->assertSame('Human value', $project->fresh()->summary);
        $this->assertSame('conflicted', $proposal->applyAttempts()->firstOrFail()->status);
        $this->assertSame('conflict', $proposal->applyAttempts()->firstOrFail()->error_code);
    }

    public function test_permission_is_rechecked_after_approval(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture();
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Never applied'],
        ]]);
        $this->approveAs($user, $workspace, $project, $proposal)->assertSessionHasNoErrors();
        $project->members()->where('user_id', $user->id)->update(['status' => ProjectMember::STATUS_INVITED]);
        $this->applyAs($user, $workspace, $project, $proposal)->assertForbidden();
        $this->assertNull($project->fresh()->summary);
    }

    public function test_repeated_apply_converges_to_one_attempt_and_one_change(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture();
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Once'],
        ]]);
        $this->approveAs($user, $workspace, $project, $proposal);
        $this->applyAs($user, $workspace, $project, $proposal);
        $version = $project->fresh()->plan_version;
        $this->applyAs($user, $workspace, $project, $proposal)->assertSessionHasNoErrors();
        $this->assertSame($version, $project->fresh()->plan_version);
        $this->assertSame(1, $proposal->applyAttempts()->count());
    }

    public function test_update_only_undo_succeeds_but_rejects_later_human_change(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture();
        $project->update(['summary' => 'Before']);
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'After'],
        ]]);
        $this->approveAs($user, $workspace, $project, $proposal);
        $this->applyAs($user, $workspace, $project, $proposal);
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])->post(route('projects.ai-proposals.undo', [$project, $proposal]))->assertSessionHasNoErrors();
        $this->assertSame('Before', $project->fresh()->summary);

        $proposal2 = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'After 2'],
        ]], 'undo-2');
        $this->approveAs($user, $workspace, $project, $proposal2);
        $this->applyAs($user, $workspace, $project, $proposal2);
        $project->update(['summary' => 'Human later']);
        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])->post(route('projects.ai-proposals.undo', [$project, $proposal2]))->assertSessionHasErrors('undo');
        $this->assertSame('Human later', $project->fresh()->summary);
    }

    public function test_ai_off_and_forbidden_category_block_context_before_proposal_creation(): void
    {
        [, $workspace, $project, $key, $token] = $this->fixture();
        WorkspaceAiSetting::where('workspace_id', $workspace->id)->update(['enabled' => false]);
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $this->payload($project))->assertForbidden();
        WorkspaceAiSetting::where('workspace_id', $workspace->id)->update(['enabled' => true, 'allowed_data_categories' => ['tasks']]);
        $this->withToken($token)->postJson('/api/v1/ai/proposals', $this->payload($project))->assertUnprocessable()->assertJsonValidationErrors('ai_context');
        $this->assertDatabaseCount('ai_proposals', 0);
    }

    public function test_failed_middle_item_rolls_back_business_data_but_keeps_attempt_history(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture();
        $proposal = $this->proposal($key, $project, [
            ['operation' => 'create', 'entity_type' => 'roadmap', 'reference_key' => 'r1', 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Rolled back']],
            ['operation' => 'create', 'entity_type' => 'roadmap', 'reference_key' => 'r2', 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Fails at runtime']],
        ]);
        $this->approveAs($user, $workspace, $project, $proposal)->assertSessionHasNoErrors();
        $applier = new class(app(AiProposalAuthorization::class), app(AiProposalValidator::class), app(ProjectPlanSnapshotService::class)) extends AiProposalScopeOneApplier
        {
            private int $seen = 0;

            protected function beforeItemApply(AiProposalItem $item): void
            {
                if (++$this->seen === 2) {
                    throw new AiProposalApplyException('temporary_test_failure', '一時的な検証用障害です。', true);
                }
            }
        };
        try {
            $applier->apply($proposal, $user);
        } catch (\Throwable) {
        }
        $this->assertDatabaseMissing('roadmaps', ['title' => 'Rolled back']);
        $attempt = $proposal->applyAttempts()->with('itemResults')->firstOrFail();
        $this->assertSame('failed', $attempt->status);
        $this->assertTrue($attempt->itemResults->contains('status', 'rolled_back'));
        $this->assertFalse((bool) $attempt->retryable === false && $attempt->error_code === null);
    }

    private function proposal(AiAccessKey $key, Project $project, array $items, string $idempotency = 'scope-one'): AiProposal
    {
        return app(AiProposalFactory::class)->create($key, $project, [
            'contract_version' => AiProposalContract::VERSION, 'expected_project_version' => $project->fresh()->plan_version,
            'idempotency_key' => $idempotency, 'title' => 'Scope 1 proposal', 'items' => $items,
        ]);
    }

    private function payload(Project $project): array
    {
        return ['project_public_id' => $project->public_id, 'contract_version' => AiProposalContract::VERSION, 'expected_project_version' => $project->plan_version, 'idempotency_key' => 'guard', 'title' => 'Guard', 'items' => [['operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id, 'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'x']]]];
    }

    private function approveAs(User $user, Workspace $workspace, Project $project, AiProposal $proposal)
    {
        return $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])->post(route('projects.ai-proposals.approve', [$project, $proposal]));
    }

    private function applyAs(User $user, Workspace $workspace, Project $project, AiProposal $proposal)
    {
        return $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])->post(route('projects.ai-proposals.apply', [$project, $proposal]));
    }

    private function fixture(): array
    {
        $user = User::factory()->create();
        $org = Organization::create(['name' => 'Scope One', 'slug' => 'scope-one-'.strtolower((string) Str::ulid())]);
        $workspace = Workspace::create(['organization_id' => $org->id, 'owner_user_id' => $user->id, 'name' => 'Scope One', 'slug' => 'scope-one-'.strtolower((string) Str::ulid())]);
        $org->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->establishSingleProductOrganization($user, $org);
        $project = Project::create(['organization_id' => $org->id, 'owning_workspace_id' => $workspace->id, 'billing_workspace_id' => $workspace->id, 'owner_user_id' => $user->id, 'name' => 'Scope One']);
        ProjectMember::create(['project_id' => $project->id, 'user_id' => $user->id, 'workspace_id' => $workspace->id, 'project_role' => ProjectMember::ROLE_OWNER, 'permission_level' => ProjectMember::PERMISSION_ADMIN, 'status' => ProjectMember::STATUS_ACTIVE]);
        WorkspaceAiSetting::create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'test', 'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES, 'terms_version' => WorkspaceAiSetting::TERMS_VERSION, 'enabled_by' => $user->id, 'enabled_at' => now()]);
        $token = 'rgos_test_'.bin2hex(random_bytes(24));
        $key = AiAccessKey::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'name' => 'Scope One', 'token_hash' => hash('sha256', $token), 'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE], 'expires_at' => now()->addHour()]);

        return [$user, $workspace, $project, $key, $token];
    }
}
