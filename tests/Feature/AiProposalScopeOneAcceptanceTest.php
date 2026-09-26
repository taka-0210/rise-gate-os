<?php

namespace Tests\Feature;

use App\Exceptions\AiProposalApplyException;
use App\Models\AiAccessKey;
use App\Models\AiProposal;
use App\Models\AiProposalApplyAttempt;
use App\Models\AiProposalItem;
use App\Models\AiProposalItemResult;
use App\Models\Improvement;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiMcpToolService;
use App\Services\AiProposalApplier;
use App\Services\AiProposalApprover;
use App\Services\AiProposalAuthorization;
use App\Services\AiProposalContract;
use App\Services\AiProposalFactory;
use App\Services\AiProposalScopeOneApplier;
use App\Services\AiProposalUndoService;
use App\Services\AiProposalValidator;
use App\Services\ProjectPlanSnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiProposalScopeOneAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_whitelisted_entities_support_the_allowed_create_and_update_contract(): void
    {
        [$user, , $project, $key] = $this->fixture('all-entities');
        [$roadmap, $improvement, $task] = $this->hierarchy($project, $user);
        $project->refresh();

        $updates = $this->proposal($key, $project, [
            ['operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id, 'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'New summary']],
            ['operation' => 'update', 'entity_type' => 'roadmap', 'target_public_id' => $roadmap->public_id, 'expected_version' => $roadmap->plan_version, 'attributes' => ['title' => 'Roadmap updated', 'purpose' => 'New purpose']],
            ['operation' => 'update', 'entity_type' => 'improvement', 'target_public_id' => $improvement->public_id, 'expected_version' => $improvement->plan_version, 'attributes' => ['title' => 'Theme updated', 'problem' => 'New problem']],
            ['operation' => 'update', 'entity_type' => 'task', 'target_public_id' => $task->public_id, 'expected_version' => $task->plan_version, 'attributes' => ['title' => 'Action updated', 'description' => 'New description']],
        ], 'all-updates');
        app(AiProposalApprover::class)->approve($updates, $user);
        app(AiProposalScopeOneApplier::class)->apply($updates, $user);

        $this->assertSame('New summary', $project->fresh()->summary);
        $this->assertSame('New purpose', $roadmap->fresh()->purpose);
        $this->assertSame('New problem', $improvement->fresh()->problem);
        $this->assertSame('New description', $task->fresh()->description);
        $this->assertSame(Project::STATUS_DRAFT, $project->fresh()->status);
        $this->assertSame(Task::STATUS_TODO, $task->fresh()->status);

        $project->refresh();
        $creates = $this->proposal($key, $project, [
            ['operation' => 'create', 'entity_type' => 'roadmap', 'reference_key' => 'new-roadmap', 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Roadmap created', 'purpose' => 'Created purpose']],
            ['operation' => 'create', 'entity_type' => 'improvement', 'reference_key' => 'new-theme', 'parent_reference' => $roadmap->public_id, 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Theme created', 'current_state' => 'Current']],
            ['operation' => 'create', 'entity_type' => 'task', 'parent_reference' => $improvement->public_id, 'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Action created', 'description' => 'Created description']],
        ], 'all-creates');
        app(AiProposalApprover::class)->approve($creates, $user);
        app(AiProposalScopeOneApplier::class)->apply($creates, $user);

        $createdTheme = Improvement::where('title', 'Theme created')->firstOrFail();
        $createdAction = Task::where('title', 'Action created')->firstOrFail();
        $this->assertSame($roadmap->id, $createdTheme->roadmap_id);
        $this->assertSame($improvement->id, $createdAction->improvement_id);
        $this->assertNull($createdTheme->assigned_to);
        $this->assertNull($createdAction->assigned_to);
    }

    public function test_same_second_human_edit_and_parent_collection_change_are_both_conflicts(): void
    {
        [$user, , $project, $key] = $this->fixture('same-second');
        [, , $task] = $this->hierarchy($project, $user);
        Carbon::setTestNow('2026-09-19 10:00:00 Asia/Tokyo');
        $project->refresh();
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'task', 'target_public_id' => $task->public_id,
            'expected_version' => $task->plan_version, 'attributes' => ['description' => 'AI edit'],
        ]], 'same-second-edit');
        app(AiProposalApprover::class)->approve($proposal, $user);
        $task->update(['description' => 'Human edit']);
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($proposal, $user));
        $this->assertSame('Human edit', $task->fresh()->description);

        $project->refresh();
        $create = $this->proposal($key, $project, [[
            'operation' => 'create', 'entity_type' => 'roadmap', 'expected_version' => $project->plan_version,
            'attributes' => ['title' => 'Must not be created'],
        ]], 'parent-version');
        app(AiProposalApprover::class)->approve($create, $user);
        Roadmap::create([
            'organization_id' => $project->organization_id, 'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id, 'title' => 'Human child', 'created_by' => $user->id,
        ]);
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($create, $user));
        $this->assertDatabaseMissing('roadmaps', ['project_id' => $project->id, 'title' => 'Must not be created']);
        Carbon::setTestNow();
    }

    public function test_soft_delete_and_restore_of_project_invalidates_an_existing_proposal(): void
    {
        [$user, , $project, $key] = $this->fixture('project-restore');
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Must stay pending'],
        ]], 'project-restore-proposal');
        app(AiProposalValidator::class)->validate($proposal);

        $project->delete();
        Project::withTrashed()->findOrFail($project->id)->restore();

        $this->expectValidation(fn () => app(AiProposalApprover::class)->approve($proposal->fresh(), $user));
        $this->assertNull($project->fresh()->summary);
        $this->assertGreaterThan($proposal->expected_project_version, $project->fresh()->plan_version);
    }

    public function test_cross_project_parent_and_tenant_tampering_are_rejected(): void
    {
        [$user, , $project, $key] = $this->fixture('tenant-a');
        [$otherUser, , $otherProject] = $this->fixture('tenant-b');
        $foreignRoadmap = Roadmap::create([
            'organization_id' => $otherProject->organization_id, 'workspace_id' => $otherProject->owning_workspace_id,
            'project_id' => $otherProject->id, 'title' => 'Foreign Roadmap', 'created_by' => $otherUser->id,
        ]);
        $project->refresh();
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'create', 'entity_type' => 'improvement', 'parent_reference' => $foreignRoadmap->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['title' => 'Cross tenant theme'],
        ]], 'cross-parent');
        $this->expectValidation(fn () => app(AiProposalApprover::class)->approve($proposal, $user));
        $this->assertDatabaseMissing('improvements', ['title' => 'Cross tenant theme']);

        $valid = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Never'],
        ]], 'tenant-tamper');
        app(AiProposalApprover::class)->approve($valid, $user);
        $valid->update(['organization_id' => $otherProject->organization_id]);
        try {
            app(AiProposalScopeOneApplier::class)->apply($valid, $user);
            $this->fail('Tenant tampering must be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertNull($project->fresh()->summary);
        }
    }

    public function test_client_role_cannot_read_confidential_proposal_or_ai_context(): void
    {
        [$owner, $workspace, $project, $key] = $this->fixture('confidential');
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Confidential summary'],
        ]], 'confidential-proposal');
        app(AiProposalValidator::class)->validate($proposal);

        $client = User::factory()->create();
        $project->organization->users()->attach($client->id, ['role' => 'admin', 'joined_at' => now()]);
        $client->workspaces()->attach($workspace->id, ['role' => 'member', 'joined_at' => now()]);
        ProjectMember::create([
            'project_id' => $project->id, 'workspace_id' => $workspace->id, 'user_id' => $client->id,
            'project_role' => ProjectMember::ROLE_CLIENT, 'permission_level' => ProjectMember::PERMISSION_VIEW,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        $this->actingAs($client)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))->assertForbidden();

        $clientKey = AiAccessKey::create([
            'workspace_id' => $workspace->id, 'user_id' => $client->id, 'name' => 'Client key',
            'token_hash' => hash('sha256', 'client-key'), 'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ],
            'expires_at' => now()->addHour(),
        ]);
        try {
            app(AiMcpToolService::class)->getProjectPlan($clientKey, $project->public_id);
            $this->fail('Client context must be denied.');
        } catch (ModelNotFoundException|ValidationException) {
            $this->assertSame($owner->id, $project->owner_user_id);
        }
    }

    public function test_client_role_cannot_apply_even_if_legacy_permission_level_is_edit(): void
    {
        [$owner, $workspace, $project, $key] = $this->fixture('client-apply');
        [, , $task] = $this->hierarchy($project, $owner);
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'task', 'target_public_id' => $task->public_id,
            'expected_version' => $task->plan_version, 'attributes' => ['description' => 'Internal only'],
        ]], 'client-apply-proposal');
        app(AiProposalValidator::class)->validate($proposal);
        app(AiProposalApprover::class)->approve($proposal, $owner);

        $client = User::factory()->create();
        $project->organization->users()->attach($client->id, ['role' => 'admin', 'joined_at' => now()]);
        $client->workspaces()->attach($workspace->id, ['role' => 'admin', 'joined_at' => now()]);
        ProjectMember::create([
            'project_id' => $project->id, 'workspace_id' => $workspace->id, 'user_id' => $client->id,
            'project_role' => ProjectMember::ROLE_CLIENT, 'permission_level' => ProjectMember::PERMISSION_EDIT,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        $this->expectException(AuthorizationException::class);
        app(AiProposalScopeOneApplier::class)->apply($proposal->fresh(), $client);
    }

    public function test_project_metadata_requires_owner_while_an_edit_member_can_apply_an_action(): void
    {
        [$owner, $workspace, $project, $key] = $this->fixture('role-matrix');
        [, , $task] = $this->hierarchy($project, $owner);
        $member = User::factory()->create();
        $project->organization->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $member->workspaces()->attach($workspace->id, ['role' => 'member', 'joined_at' => now()]);
        ProjectMember::create([
            'project_id' => $project->id, 'workspace_id' => $workspace->id, 'user_id' => $member->id,
            'project_role' => ProjectMember::ROLE_CODER, 'permission_level' => ProjectMember::PERMISSION_EDIT,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        $projectProposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->fresh()->plan_version, 'attributes' => ['summary' => 'Owner only'],
        ]], 'role-project');
        app(AiProposalValidator::class)->validate($projectProposal);
        $this->expectAuthorization(fn () => app(AiProposalApprover::class)->approve($projectProposal, $member));

        $actionProposal = $this->proposal($key, $project->fresh(), [[
            'operation' => 'update', 'entity_type' => 'task', 'target_public_id' => $task->public_id,
            'expected_version' => $task->fresh()->plan_version, 'attributes' => ['description' => 'Member applied'],
        ]], 'role-action');
        app(AiProposalValidator::class)->validate($actionProposal);
        app(AiProposalApprover::class)->approve($actionProposal, $member);
        app(AiProposalScopeOneApplier::class)->apply($actionProposal->fresh(), $member);

        $this->assertNull($project->fresh()->summary);
        $this->assertSame('Member applied', $task->fresh()->description);
    }

    public function test_content_change_invalidates_approval_and_revision_clears_it(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture('approval-stale');
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Approved value'],
        ]], 'approval-stale');
        app(AiProposalApprover::class)->approve($proposal, $user);
        $proposal->items()->firstOrFail()->update(['after' => ['summary' => 'Tampered value'], 'attributes' => ['summary' => 'Tampered value']]);
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($proposal, $user));
        $this->assertSame('approval_stale', $proposal->applyAttempts()->firstOrFail()->error_code);
        $this->assertNull($project->fresh()->summary);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('projects.ai-proposals.request-revision', [$project, $proposal]), [
                'overall_feedback' => '最新状態で再提案してください。',
            ])->assertRedirect();
        $proposal->refresh();
        $this->assertSame(AiProposal::STATUS_REJECTED, $proposal->status);
        $this->assertNull($proposal->approved_by);
        $this->assertNull($proposal->approved_content_hash);
        $this->assertDatabaseHas('ai_requests', ['project_id' => $project->id, 'status' => 'pending']);
    }

    public function test_user_and_tenant_memberships_are_rechecked_after_approval(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture('membership-recheck');
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Must stay null'],
        ]], 'membership-recheck');
        app(AiProposalApprover::class)->approve($proposal, $user);

        $user->update(['is_active' => false]);
        $this->expectAuthorization(fn () => app(AiProposalScopeOneApplier::class)->apply($proposal, $user->fresh()));
        $user->update(['is_active' => true]);

        $project->organization->users()->detach($user->id);
        $this->expectAuthorization(fn () => app(AiProposalScopeOneApplier::class)->apply($proposal, $user->fresh()));
        $project->organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        $user->workspaces()->detach($workspace->id);
        $this->expectAuthorization(fn () => app(AiProposalScopeOneApplier::class)->apply($proposal, $user->fresh()));
        $this->assertNull($project->fresh()->summary);
        $this->assertDatabaseCount('ai_proposal_apply_attempts', 0);
    }

    public function test_only_retryable_failure_creates_a_second_attempt(): void
    {
        [$user, , $project, $key] = $this->fixture('retry');
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Retried'],
        ]], 'retryable');
        app(AiProposalApprover::class)->approve($proposal, $user);
        $applier = new class(app(AiProposalAuthorization::class), app(AiProposalValidator::class), app(ProjectPlanSnapshotService::class)) extends AiProposalScopeOneApplier
        {
            private bool $failed = false;

            protected function beforeItemApply(AiProposalItem $item): void
            {
                if (! $this->failed) {
                    $this->failed = true;
                    throw new AiProposalApplyException('temporary_test_failure', '一時的な障害です。', true);
                }
            }
        };
        $this->expectValidation(fn () => $applier->apply($proposal, $user));
        $applier->apply($proposal, $user);
        $this->assertSame('Retried', $project->fresh()->summary);
        $this->assertSame([1, 2], $proposal->applyAttempts()->orderBy('attempt_number')->pluck('attempt_number')->all());
        $this->assertSame([AiProposalApplyAttempt::STATUS_FAILED, AiProposalApplyAttempt::STATUS_APPLIED], $proposal->applyAttempts()->orderBy('attempt_number')->pluck('status')->all());

        $project->refresh();
        $conflict = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Conflict'],
        ]], 'non-retryable');
        app(AiProposalApprover::class)->approve($conflict, $user);
        $project->update(['summary' => 'Human']);
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($conflict, $user));
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($conflict, $user));
        $this->assertSame(1, $conflict->applyAttempts()->count());
        $this->assertFalse((bool) $conflict->applyAttempts()->firstOrFail()->retryable);
    }

    public function test_processing_attempt_recovery_and_commit_response_replay_do_not_double_apply(): void
    {
        [$user, , $project, $key] = $this->fixture('processing');
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Recovered'],
        ]], 'processing-stale');
        app(AiProposalApprover::class)->approve($proposal, $user);
        $stale = $proposal->applyAttempts()->create([
            'actor_id' => $user->id, 'attempt_number' => 1, 'idempotency_key' => 'apply:stale',
            'status' => AiProposalApplyAttempt::STATUS_PROCESSING, 'started_at' => now()->subMinutes(6),
        ]);
        app(AiProposalScopeOneApplier::class)->apply($proposal, $user);
        $this->assertSame(AiProposalApplyAttempt::STATUS_FAILED, $stale->fresh()->status);
        $this->assertSame(AiProposalItemResult::STATUS_NOT_RUN, $stale->itemResults()->firstOrFail()->status);
        $this->assertSame(2, $proposal->applyAttempts()->count());

        $version = $project->fresh()->plan_version;
        $appliedAttempt = $proposal->applyAttempts()->where('status', AiProposalApplyAttempt::STATUS_APPLIED)->firstOrFail();
        $appliedAttempt->update(['status' => AiProposalApplyAttempt::STATUS_PROCESSING, 'finished_at' => null]);
        app(AiProposalScopeOneApplier::class)->apply($proposal->fresh(), $user);
        $this->assertSame($version, $project->fresh()->plan_version);
        $this->assertSame(AiProposalApplyAttempt::STATUS_APPLIED, $appliedAttempt->fresh()->status);

        $project->refresh();
        $freshProcessing = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['current_state' => 'Not yet'],
        ]], 'processing-fresh');
        app(AiProposalApprover::class)->approve($freshProcessing, $user);
        $freshProcessing->applyAttempts()->create([
            'actor_id' => $user->id, 'attempt_number' => 1, 'idempotency_key' => 'apply:fresh',
            'status' => AiProposalApplyAttempt::STATUS_PROCESSING, 'started_at' => now(),
        ]);
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($freshProcessing, $user));
        $this->assertNull($project->fresh()->current_state);
        $this->assertSame(1, $freshProcessing->applyAttempts()->count());
    }

    public function test_mixed_update_undo_restores_values_but_create_and_revoked_access_are_not_undoable(): void
    {
        [$user, , $project, $key] = $this->fixture('undo-boundary');
        [$roadmap, $improvement, $task] = $this->hierarchy($project, $user);
        $project->update(['summary' => 'Project before']);
        $roadmap->update(['purpose' => 'Roadmap before']);
        $improvement->update(['problem' => 'Theme before']);
        $task->update(['description' => 'Action before']);
        $project->refresh();
        $proposal = $this->proposal($key, $project, [
            ['operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id, 'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Project after']],
            ['operation' => 'update', 'entity_type' => 'roadmap', 'target_public_id' => $roadmap->public_id, 'expected_version' => $roadmap->plan_version, 'attributes' => ['purpose' => 'Roadmap after']],
            ['operation' => 'update', 'entity_type' => 'improvement', 'target_public_id' => $improvement->public_id, 'expected_version' => $improvement->plan_version, 'attributes' => ['problem' => 'Theme after']],
            ['operation' => 'update', 'entity_type' => 'task', 'target_public_id' => $task->public_id, 'expected_version' => $task->plan_version, 'attributes' => ['description' => 'Action after']],
        ], 'mixed-undo');
        app(AiProposalApprover::class)->approve($proposal, $user);
        app(AiProposalScopeOneApplier::class)->apply($proposal, $user);
        $undo = app(AiProposalUndoService::class)->undo($proposal->fresh(), $user);
        $this->assertSame('applied', $undo->status);
        $this->assertSame('Project before', $project->fresh()->summary);
        $this->assertSame('Roadmap before', $roadmap->fresh()->purpose);
        $this->assertSame('Theme before', $improvement->fresh()->problem);
        $this->assertSame('Action before', $task->fresh()->description);
        $this->assertSame(AiProposal::STATUS_APPLIED, $proposal->fresh()->status);

        $project->refresh();
        $create = $this->proposal($key, $project, [[
            'operation' => 'create', 'entity_type' => 'roadmap', 'expected_version' => $project->plan_version,
            'attributes' => ['title' => 'Create cannot be undone'],
        ]], 'create-no-undo');
        app(AiProposalApprover::class)->approve($create, $user);
        app(AiProposalScopeOneApplier::class)->apply($create, $user);
        $this->expectValidation(fn () => app(AiProposalUndoService::class)->undo($create->fresh(), $user));
        $this->assertDatabaseHas('roadmaps', ['title' => 'Create cannot be undone']);

        $project->members()->where('user_id', $user->id)->update(['status' => ProjectMember::STATUS_INVITED]);
        try {
            app(AiProposalUndoService::class)->undo($proposal->fresh(), $user);
            $this->fail('Revoked member must not undo.');
        } catch (AuthorizationException) {
            $this->assertSame('Project before', $project->fresh()->summary);
        }
    }

    public function test_undo_detects_a_later_change_to_an_untouched_scope_field(): void
    {
        [$user, , $project, $key] = $this->fixture('undo-full-snapshot');
        $project->update(['summary' => 'Before summary', 'current_state' => 'Before state']);
        $project->refresh();
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Applied summary'],
        ]], 'undo-full-snapshot-proposal');
        app(AiProposalValidator::class)->validate($proposal);
        app(AiProposalApprover::class)->approve($proposal, $user);
        app(AiProposalScopeOneApplier::class)->apply($proposal->fresh(), $user);

        DB::table('projects')->where('id', $project->id)->update(['current_state' => 'Later state']);

        $this->expectValidation(fn () => app(AiProposalUndoService::class)->undo($proposal->fresh(), $user));
        $this->assertSame('Applied summary', $project->fresh()->summary);
        $this->assertSame('Later state', $project->fresh()->current_state);
    }

    public function test_feature_flag_and_legacy_adapter_never_fall_back_to_unsafe_apply(): void
    {
        [$user, , $project, $key] = $this->fixture('feature-flag');
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Blocked'],
        ]], 'feature-flag');
        app(AiProposalApprover::class)->approve($proposal, $user);
        config(['services.ai.scope_one_apply_enabled' => false]);
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($proposal, $user));
        $this->assertNull($project->fresh()->summary);
        $this->assertDatabaseCount('ai_proposal_apply_attempts', 0);

        config(['services.ai.scope_one_apply_enabled' => true]);
        $legacy = AiProposal::create([
            'organization_id' => $project->organization_id, 'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id, 'source' => 'codex', 'mode' => AiProposal::MODE_REPLACE_TIMELINE,
            'idempotency_key' => 'legacy', 'title' => 'Legacy', 'status' => AiProposal::STATUS_APPROVED,
            'requested_by' => $user->id,
        ]);
        $legacy->items()->create([
            'operation' => AiProposalItem::OPERATION_DELETE, 'entity_type' => 'roadmap',
            'target_public_id' => 'legacy-target', 'attributes' => [],
        ]);
        $this->expectValidation(fn () => app(AiProposalApplier::class)->apply($legacy, $user));
        $this->assertNull($project->fresh()->summary);
    }

    public function test_scope_one_ui_reports_diff_success_conflict_and_processing_from_persisted_state(): void
    {
        [$user, $workspace, $project, $key] = $this->fixture('scope-ui');
        $project->update(['summary' => 'Before UI']);
        $proposal = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'After UI'],
        ]], 'ui-success');
        app(AiProposalValidator::class)->validate($proposal);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()->assertSee('変更前')->assertSee('変更後')->assertSee('Before UI')->assertSee('After UI');
        app(AiProposalApprover::class)->approve($proposal, $user);
        app(AiProposalScopeOneApplier::class)->apply($proposal, $user);
        $this->get(route('projects.ai-proposals.show', [$project, $proposal]))
            ->assertOk()->assertSee('1件の変更を反映しました')->assertSee('Projectを確認')->assertSee('説明項目を元に戻す');

        $project->refresh();
        $conflict = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['summary' => 'Conflict UI'],
        ]], 'ui-conflict');
        app(AiProposalApprover::class)->approve($conflict, $user);
        $project->update(['summary' => 'Human UI']);
        $this->expectValidation(fn () => app(AiProposalScopeOneApplier::class)->apply($conflict, $user));
        $this->get(route('projects.ai-proposals.show', [$project, $conflict]))
            ->assertOk()->assertSee('変更は反映されていません')->assertSee('最新状態から再提案を依頼')->assertDontSee('Error:');

        $project->refresh();
        $processing = $this->proposal($key, $project, [[
            'operation' => 'update', 'entity_type' => 'project', 'target_public_id' => $project->public_id,
            'expected_version' => $project->plan_version, 'attributes' => ['current_state' => 'Processing UI'],
        ]], 'ui-processing');
        app(AiProposalApprover::class)->approve($processing, $user);
        $processing->applyAttempts()->create([
            'actor_id' => $user->id, 'attempt_number' => 1, 'idempotency_key' => 'apply:ui-processing',
            'status' => AiProposalApplyAttempt::STATUS_PROCESSING, 'started_at' => now(),
        ]);
        $this->get(route('projects.ai-proposals.show', [$project, $processing]))
            ->assertOk()->assertSee('変更を適用しています')->assertDontSee('変更を適用</button>', false);
    }

    private function fixture(string $slug): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Org '.$slug, 'slug' => $slug.'-'.strtolower((string) Str::ulid())]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id, 'owner_user_id' => $user->id,
            'name' => 'Workspace '.$slug, 'slug' => $slug.'-'.strtolower((string) Str::ulid()),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->establishSingleProductOrganization($user, $organization);
        $project = Project::create([
            'organization_id' => $organization->id, 'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id, 'owner_user_id' => $user->id,
            'name' => 'Project '.$slug,
        ]);
        ProjectMember::create([
            'project_id' => $project->id, 'workspace_id' => $workspace->id, 'user_id' => $user->id,
            'project_role' => ProjectMember::ROLE_OWNER, 'permission_level' => ProjectMember::PERMISSION_ADMIN,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        WorkspaceAiSetting::create([
            'workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'test',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
        ]);
        $key = AiAccessKey::create([
            'workspace_id' => $workspace->id, 'user_id' => $user->id, 'name' => 'Test key',
            'token_hash' => hash('sha256', 'token-'.$slug),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE],
            'expires_at' => now()->addHour(),
        ]);

        return [$user, $workspace, $project, $key];
    }

    private function hierarchy(Project $project, User $user): array
    {
        $roadmap = Roadmap::create([
            'organization_id' => $project->organization_id, 'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id, 'title' => 'Roadmap before', 'purpose' => 'Purpose before',
            'created_by' => $user->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $project->organization_id, 'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id, 'roadmap_id' => $roadmap->id, 'title' => 'Theme before',
            'problem' => 'Problem before', 'proposed_by' => $user->id,
        ]);
        $task = Task::create([
            'organization_id' => $project->organization_id, 'workspace_id' => $project->owning_workspace_id,
            'project_id' => $project->id, 'improvement_id' => $improvement->id, 'title' => 'Action before',
            'description' => 'Description before', 'created_by' => $user->id,
        ]);

        return [$roadmap, $improvement, $task];
    }

    private function proposal(AiAccessKey $key, Project $project, array $items, string $idempotency): AiProposal
    {
        return app(AiProposalFactory::class)->create($key, $project, [
            'contract_version' => AiProposalContract::VERSION,
            'expected_project_version' => $project->fresh()->plan_version,
            'idempotency_key' => $idempotency,
            'title' => 'Acceptance proposal',
            'items' => $items,
        ]);
    }

    private function expectValidation(callable $callback): ValidationException
    {
        try {
            $callback();
            $this->fail('ValidationException was not thrown.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->validator->errors()->all());

            return $error;
        }
    }

    private function expectAuthorization(callable $callback): void
    {
        try {
            $callback();
            $this->fail('AuthorizationException was not thrown.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }
}
