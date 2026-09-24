<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\AiAccessKey;
use App\Models\OrganizationGroup;
use App\Models\OrganizationGroupMembership;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\ProjectPlanVersion;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiProposalContract;
use App\Services\AiProposalApprover;
use App\Services\AiProposalFactory;
use App\Services\AiProposalScopeOneApplier;
use App\Services\AiProposalValidator;
use App\Services\ProjectExecution\ProjectExecutionAccess;
use App\Services\ProjectExecution\ProjectExecutionProposalContract;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use App\Services\ProjectPlanRestoreService;
use App\Services\ProjectPlanSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ScopeEightProjectActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_minimum_project_and_owner_are_created_atomically(): void
    {
        [$owner, , $workspace] = $this->tenant('owner@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, [
            'name' => 'New Service', 'purpose' => 'Solve a customer problem',
            'expected_outcome' => 'A measurable launch',
        ]);

        $this->assertTrue($project->usesScopeEight());
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id, 'user_id' => $owner->id,
            'project_role' => 'owner', 'status' => 'active',
        ]);
        $this->assertDatabaseHas('project_execution_events', ['project_id' => $project->id, 'event' => 'project.created']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $writer->createProject($owner, $workspace, ['name' => 'Incomplete']);
    }

    public function test_visibility_read_access_is_separate_from_explicit_write_access(): void
    {
        [$owner, $organization, $workspace] = $this->tenant('owner@example.com');
        $viewer = $this->join($organization, $workspace, 'viewer@example.com');
        $outsider = User::factory()->create();
        $writer = app(ProjectExecutionWriter::class);
        $access = app(ProjectExecutionAccess::class);
        $project = $writer->createProject($owner, $workspace, [
            'name' => 'Visible', 'purpose' => 'Share progress', 'expected_outcome' => 'Shared understanding',
        ]);

        $this->assertTrue($access->canRead($viewer, $project));
        $this->assertFalse($access->canCreateAction($viewer, $project));
        $this->assertFalse($access->canRead($outsider, $project));

        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Leadership']);
        $membership = OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $viewer->id)->firstOrFail();
        OrganizationGroupMembership::create(['organization_group_id' => $group->id, 'organization_user_id' => $membership->id, 'added_by' => $owner->id]);
        $project = $writer->setVisibility($owner, $project->fresh(), Project::VISIBILITY_GROUP, [$group->id], null, $project->fresh()->plan_version);
        $this->assertTrue($access->canRead($viewer, $project));
        $this->assertFalse($access->canCreateAction($viewer, $project));
        $this->assertDatabaseMissing('project_members', ['project_id' => $project->id, 'user_id' => $viewer->id]);
        OrganizationGroupMembership::query()->delete();
        $this->assertFalse($access->canRead($viewer, $project->fresh()));

        $project = $writer->setVisibility($owner, $project->fresh(), Project::VISIBILITY_CONFIDENTIAL, [], 'Board matter', $project->fresh()->plan_version);
        $this->assertFalse($access->canRead($viewer, $project));
        $this->assertTrue($access->canRead($owner, $project));
    }

    public function test_direct_action_requires_assignee_and_done_condition_and_honors_review(): void
    {
        [$owner, $organization, $workspace] = $this->tenant('owner@example.com');
        $assignee = $this->join($organization, $workspace, 'assignee@example.com');
        $reviewer = $this->join($organization, $workspace, 'reviewer@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, ['name' => 'Execute', 'purpose' => 'Move', 'expected_outcome' => 'Done']);
        $writer->addMember($owner, $project->fresh(), $assignee, $workspace, ['member'], $project->fresh()->plan_version);
        $writer->addMember($owner, $project->fresh(), $reviewer, $workspace, ['member'], $project->fresh()->plan_version);
        $action = $writer->createAction($owner, $project->fresh(), [
            'title' => 'Ship', 'done_condition' => 'Customer can use it',
            'assigned_to' => $assignee->id, 'reviewer_user_id' => $reviewer->id,
        ], $project->fresh()->plan_version);

        $this->assertNull($action->improvement_id);
        $action = $writer->transitionAction($assignee, $action, 'start', $project->fresh()->plan_version);
        $action = $writer->transitionAction($assignee, $action, 'complete', $project->fresh()->plan_version);
        $this->assertSame(Task::STATUS_REVIEW_PENDING, $action->status);
        $action = $writer->transitionAction($reviewer, $action, 'confirm', $project->fresh()->plan_version);
        $this->assertSame(Task::STATUS_DONE, $action->status);
        $this->assertSame($assignee->id, $action->completed_by_user_id);
    }

    public function test_snapshot_v2_contains_direct_actions_and_legacy_restore_fails_closed(): void
    {
        [$owner, , $workspace] = $this->tenant('owner@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, ['name' => 'Snapshot', 'purpose' => 'Protect', 'expected_outcome' => 'Recover']);
        $writer->createAction($owner, $project->fresh(), ['title' => 'Direct', 'done_condition' => 'Verified', 'assigned_to' => $owner->id], $project->fresh()->plan_version);
        $snapshot = app(ProjectPlanSnapshotService::class)->capture($project->fresh());
        $this->assertSame(2, $snapshot['format_version']);
        $this->assertCount(1, $snapshot['direct_actions']);

        $legacy = ProjectPlanVersion::create([
            'project_id' => $project->id, 'version_number' => 1, 'version_type' => ProjectPlanVersion::TYPE_TIMELINE,
            'title' => 'Legacy', 'previous_snapshot' => [], 'plan_snapshot' => ['project' => ['public_id' => $project->public_id]], 'created_by' => $owner->id,
        ]);
        $this->expectException(RuntimeException::class);
        app(ProjectPlanRestoreService::class)->preview($project, $legacy);
    }

    public function test_scope_eight_ai_adapter_does_not_expand_v1_allowlist(): void
    {
        $this->assertSame(['title', 'description'], AiProposalContract::ALLOWED_ATTRIBUTES['task']);
        $this->assertContains('done_condition', ProjectExecutionProposalContract::ALLOWED_ATTRIBUTES['task']);
        $this->assertContains('assigned_to', ProjectExecutionProposalContract::ALLOWED_ATTRIBUTES['task']);
        $this->assertNotSame(AiProposalContract::VERSION, ProjectExecutionProposalContract::VERSION);
    }

    public function test_scope_eight_ai_proposal_uses_existing_approval_and_atomic_apply_engine(): void
    {
        [$owner, , $workspace] = $this->tenant('owner@example.com');
        $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, [
            'name' => 'AI execution', 'purpose' => 'Safe proposals', 'expected_outcome' => 'Reviewed action',
        ]);
        $key = AiAccessKey::create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'name' => 'Scope 8 test',
            'token_hash' => hash('sha256', 'scope-8-token'),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE],
            'expires_at' => now()->addHour(),
        ]);
        $proposal = app(AiProposalFactory::class)->create($key, $project->fresh(), [
            'contract_version' => ProjectExecutionProposalContract::VERSION,
            'expected_project_version' => $project->fresh()->plan_version,
            'idempotency_key' => 'scope-8-direct-action', 'title' => 'Add direct action',
            'items' => [[
                'operation' => 'create', 'entity_type' => 'task', 'reference_key' => 'new-action',
                'parent_reference' => $project->public_id, 'expected_version' => $project->fresh()->plan_version,
                'attributes' => ['title' => 'AI action', 'done_condition' => 'Human verified', 'assigned_to' => $owner->id],
            ]],
        ]);
        $proposal = app(AiProposalValidator::class)->validate($proposal);
        $this->assertFalse($proposal->items->contains('validation_status', AiProposalValidator::STATUS_INVALID));
        app(AiProposalApprover::class)->approve($proposal, $owner);
        app(AiProposalScopeOneApplier::class)->apply($proposal, $owner);

        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'improvement_id' => null, 'title' => 'AI action', 'done_condition' => 'Human verified', 'assigned_to' => $owner->id]);
        $this->assertDatabaseHas('ai_proposals', ['id' => $proposal->id, 'status' => 'applied']);
        $this->assertDatabaseHas('ai_proposal_item_results', ['ai_proposal_item_id' => $proposal->items->first()->id, 'status' => 'applied']);
    }

    public function test_action_validation_and_ai_member_validation_fail_without_partial_write(): void
    {
        [$owner, , $workspace] = $this->tenant('owner@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, ['name' => 'Guarded', 'purpose' => 'Guard writes', 'expected_outcome' => 'No partial data']);

        try {
            $writer->createAction($owner, $project->fresh(), ['title' => 'Incomplete', 'assigned_to' => $owner->id], $project->fresh()->plan_version);
            $this->fail('Missing Done Condition must fail.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertDatabaseMissing('tasks', ['project_id' => $project->id, 'title' => 'Incomplete']);
        }

        $outsider = User::factory()->create();
        $key = AiAccessKey::create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'name' => 'Guard key',
            'token_hash' => hash('sha256', 'guard-key'),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE],
            'expires_at' => now()->addHour(),
        ]);
        $proposal = app(AiProposalFactory::class)->create($key, $project->fresh(), [
            'contract_version' => ProjectExecutionProposalContract::VERSION,
            'expected_project_version' => $project->fresh()->plan_version,
            'idempotency_key' => 'invalid-assignee', 'title' => 'Invalid assignee',
            'items' => [[
                'operation' => 'create', 'entity_type' => 'task', 'reference_key' => 'bad-action',
                'parent_reference' => $project->public_id, 'expected_version' => $project->fresh()->plan_version,
                'attributes' => ['title' => 'Bad', 'done_condition' => 'Done', 'assigned_to' => $outsider->id],
            ]],
        ]);
        $proposal = app(AiProposalValidator::class)->validate($proposal);
        $this->assertSame(AiProposalValidator::STATUS_INVALID, $proposal->items->first()->validation_status);
        $this->assertStringContainsString('active explicit execution member', $proposal->items->first()->validation_message);
    }

    public function test_owner_transfer_is_single_and_stale_second_transfer_fails(): void
    {
        [$owner, $organization, $workspace] = $this->tenant('owner@example.com');
        $next = $this->join($organization, $workspace, 'next@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, ['name' => 'Transfer', 'purpose' => 'One owner', 'expected_outcome' => 'Atomic responsibility']);
        $writer->addMember($owner, $project->fresh(), $next, $workspace, ['member'], $project->fresh()->plan_version);
        $staleVersion = $project->fresh()->plan_version;
        $writer->transferOwner($owner, $project->fresh(), $next, $staleVersion);

        $this->assertSame($next->id, $project->fresh()->owner_user_id);
        $this->assertSame(1, $project->members()->where('status', 'active')->where('project_role', 'owner')->count());
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $writer->transferOwner($owner, $project->fresh(), $owner, $staleVersion);
    }

    public function test_move_keeps_action_identity_and_parent_close_keeps_children_open(): void
    {
        [$owner, , $workspace] = $this->tenant('owner@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, ['name' => 'Move', 'purpose' => 'Preserve identity', 'expected_outcome' => 'Safe hierarchy']);
        $roadmap = $writer->createRoadmap($owner, $project->fresh(), ['title' => 'Roadmap'], $project->fresh()->plan_version);
        $theme = $writer->createTheme($owner, $project->fresh(), $roadmap, ['title' => 'Theme'], $project->fresh()->plan_version);
        $action = $writer->createAction($owner, $project->fresh(), ['title' => 'Keep me', 'done_condition' => 'Moved', 'assigned_to' => $owner->id, 'due_date' => '2026-12-31'], $project->fresh()->plan_version);
        $publicId = $action->public_id;
        $writer->moveAction($owner, $action, $theme, 1, $project->fresh()->plan_version);
        $action->refresh();
        $this->assertSame($publicId, $action->public_id);
        $this->assertSame('2026-12-31', $action->due_date->format('Y-m-d'));
        $this->assertSame($theme->id, $action->improvement_id);

        $writer->completeParent($owner, $theme->fresh(), $project->fresh()->plan_version, $project->fresh()->plan_version);
        $this->assertSame(Task::STATUS_TODO, $action->fresh()->status);
        $this->assertDatabaseHas('project_execution_events', ['project_id' => $project->id, 'subject_public_id' => $publicId, 'event' => 'action.moved']);
    }

    public function test_project_reviewer_confirms_owner_completion_request(): void
    {
        [$owner, $organization, $workspace] = $this->tenant('owner@example.com');
        $reviewer = $this->join($organization, $workspace, 'reviewer@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, ['name' => 'Reviewed', 'purpose' => 'Review close', 'expected_outcome' => 'Confirmed']);
        $writer->addMember($owner, $project->fresh(), $reviewer, $workspace, ['member'], $project->fresh()->plan_version);
        $writer->setProjectReviewer($owner, $project->fresh(), $reviewer, $project->fresh()->plan_version);
        $writer->completeParent($owner, $project->fresh(), $project->fresh()->plan_version, $project->fresh()->plan_version);
        $this->assertSame(Project::REVIEW_PENDING, $project->fresh()->review_status);
        $writer->reviewProject($reviewer, $project->fresh(), true, $project->fresh()->plan_version);
        $this->assertSame(Project::STATUS_COMPLETED, $project->fresh()->status);
        $this->assertSame(Project::REVIEW_CONFIRMED, $project->fresh()->review_status);
    }

    public function test_archive_reopen_and_member_leave_preserve_identity_and_actor_history(): void
    {
        [$owner, $organization, $workspace] = $this->tenant('owner@example.com');
        $member = $this->join($organization, $workspace, 'member@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, ['name' => 'Lifecycle', 'purpose' => 'Keep history', 'expected_outcome' => 'Reversible state']);
        $membership = $writer->addMember($owner, $project->fresh(), $member, $workspace, ['viewer'], $project->fresh()->plan_version);
        $action = $writer->createAction($owner, $project->fresh(), ['title' => 'Archive me', 'done_condition' => 'Can reopen', 'assigned_to' => $owner->id], $project->fresh()->plan_version);
        $publicId = $action->public_id;

        $writer->archive($owner, $action, $project->fresh()->plan_version, 'Temporary archive');
        $this->assertSoftDeleted('tasks', ['id' => $action->id]);
        $restored = $writer->reopen($owner, $action, $project->fresh(), $project->fresh()->plan_version, 'Resume work');
        $this->assertSame($publicId, $restored->public_id);
        $writer->leaveMember($owner, $project->fresh(), $membership, 'No longer participating', $project->fresh()->plan_version);

        $this->assertDatabaseHas('project_members', ['id' => $membership->id, 'status' => 'left', 'left_by_user_id' => $owner->id]);
        $this->assertDatabaseHas('project_execution_events', ['project_id' => $project->id, 'actor_user_id' => $owner->id, 'subject_public_id' => $publicId, 'event' => 'entity.reopened']);
        $this->assertDatabaseHas('project_execution_events', ['project_id' => $project->id, 'actor_user_id' => $owner->id, 'event' => 'member.left']);
    }

    public function test_company_read_projection_is_available_without_exposing_manage_to_non_member(): void
    {
        [$owner, $organization, $workspace] = $this->tenant('owner@example.com');
        $reader = $this->join($organization, $workspace, 'reader@example.com');
        $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, [
            'name' => 'Company visible', 'purpose' => 'Let the company understand', 'expected_outcome' => 'Aligned team',
        ]);
        $session = ['access_mode' => 'workspace', 'current_company_id' => $organization->id, 'current_company_access_epoch' => 1, 'credential_generation' => 1];

        $this->actingAs($reader)->withSession($session)->get(route('project-execution.index'))->assertOk()->assertSee('Company visible');
        $this->actingAs($reader)->withSession($session)->get(route('project-execution.show', $project))->assertOk()->assertSee('Let the company understand')->assertDontSee('実行を管理');
        $this->actingAs($reader)->withSession($session)->get(route('project-execution.manage', $project))->assertForbidden();
    }

    public function test_scope_eight_ai_user_journey_connects_request_review_approval_apply_and_result(): void
    {
        config(['product_ux.organization_admission_enabled' => false, 'app.url' => 'http://localhost']);
        app('url')->forceRootUrl('http://localhost');
        [$owner, $organization, $workspace] = $this->tenant('ai-journey-owner@example.com');
        $writer = app(ProjectExecutionWriter::class);
        $project = $writer->createProject($owner, $workspace, [
            'name' => 'AI Journey', 'purpose' => 'Connect intent to action', 'expected_outcome' => 'A reviewed execution plan',
        ]);
        $session = ['access_mode' => 'workspace', 'current_company_id' => $organization->id, 'current_company_access_epoch' => 1, 'credential_generation' => 1];
        $this->assertTrue($project->usesScopeEight());
        $this->assertNotNull(app(ProjectExecutionAccess::class)->activeExplicitMember($owner, $project));

        $this->actingAs($owner)->withSession($session)->get(route('project-execution.show', $project))
            ->assertOk()->assertSee('AIと実行計画をつくる');
        $this->actingAs($owner)->withSession($session)->post(route('project-execution.ai.requests.store', $project), [
            'title' => 'Launch plan', 'instructions' => 'Create an accountable launch plan.',
        ])->assertRedirect(route('project-execution.ai.index', $project));
        $this->assertDatabaseHas('ai_requests', ['project_id' => $project->id, 'status' => 'pending']);
        $this->assertStringContainsString('Project Purpose: Connect intent to action', $project->aiRequests()->latest()->value('instructions'));

        $key = AiAccessKey::create([
            'workspace_id' => $workspace->id, 'user_id' => $owner->id, 'name' => 'Journey key',
            'token_hash' => hash('sha256', 'journey-key'),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ, AiAccessKey::SCOPE_PROPOSALS_CREATE],
            'expires_at' => now()->addHour(),
        ]);
        $proposal = app(AiProposalFactory::class)->create($key, $project->fresh(), [
            'contract_version' => ProjectExecutionProposalContract::VERSION,
            'expected_project_version' => $project->fresh()->plan_version,
            'idempotency_key' => 'journey-proposal', 'title' => 'Accountable launch',
            'items' => [[
                'operation' => 'create', 'entity_type' => 'task', 'reference_key' => 'direct-launch',
                'parent_reference' => $project->public_id, 'expected_version' => $project->fresh()->plan_version,
                'attributes' => ['title' => 'Confirm launch readiness', 'description' => 'Review launch inputs.', 'done_condition' => 'Owner confirms readiness.', 'assigned_to' => $owner->id, 'reviewer_user_id' => null, 'due_date' => '2026-12-31'],
            ]],
        ]);
        $proposal = app(AiProposalValidator::class)->validate($proposal);

        $this->actingAs($owner)->withSession($session)->get(route('project-execution.ai.proposals.show', [$project, $proposal]))
            ->assertOk()->assertSee('DIRECT ACTION')->assertSee('Done Condition')->assertSee('Assignee')->assertSee('Reviewer')->assertSee('2026-12-31');
        $this->actingAs($owner)->withSession($session)->post(route('project-execution.ai.proposals.approve', [$project, $proposal]))
            ->assertRedirect(route('project-execution.ai.proposals.show', [$project, $proposal]));
        $this->assertDatabaseHas('ai_proposals', ['id' => $proposal->id, 'status' => 'approved']);
        $this->actingAs($owner)->withSession($session)->post(route('project-execution.ai.proposals.apply', [$project, $proposal]))
            ->assertRedirect(route('project-execution.ai.proposals.show', [$project, $proposal]));
        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'title' => 'Confirm launch readiness', 'improvement_id' => null]);
        $this->actingAs($owner)->withSession($session)->get(route('project-execution.ai.proposals.show', [$project, $proposal]))
            ->assertOk()->assertSee('APPLY RESULT')->assertSee('APPLIED')->assertSee('Scope 8 Projectへ戻る');

        $revision = app(AiProposalFactory::class)->create($key, $project->fresh(), [
            'contract_version' => ProjectExecutionProposalContract::VERSION,
            'expected_project_version' => $project->fresh()->plan_version,
            'idempotency_key' => 'journey-revision', 'title' => 'Needs revision',
            'items' => [[
                'operation' => 'create', 'entity_type' => 'task', 'reference_key' => 'revise-me',
                'parent_reference' => $project->public_id, 'expected_version' => $project->fresh()->plan_version,
                'attributes' => ['title' => 'Draft action', 'done_condition' => 'Reviewed', 'assigned_to' => $owner->id],
            ]],
        ]);
        app(AiProposalValidator::class)->validate($revision);
        $this->actingAs($owner)->withSession($session)->post(route('project-execution.ai.proposals.revision', [$project, $revision]), [
            'overall_feedback' => 'Add a measurable deadline and reviewer.',
        ])->assertRedirect(route('project-execution.ai.index', $project));
        $this->assertDatabaseHas('ai_proposals', ['id' => $revision->id, 'status' => 'rejected']);
        $this->assertDatabaseHas('ai_requests', ['project_id' => $project->id, 'status' => 'pending', 'title' => '「Needs revision」の修正依頼']);
    }

    public function test_scope_eight_ai_journey_denies_company_reader_without_explicit_project_membership(): void
    {
        config(['product_ux.organization_admission_enabled' => false, 'app.url' => 'http://localhost']);
        app('url')->forceRootUrl('http://localhost');
        [$owner, $organization, $workspace] = $this->tenant('ai-owner@example.com');
        $reader = $this->join($organization, $workspace, 'ai-reader@example.com');
        $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, [
            'name' => 'Guarded AI', 'purpose' => 'Protect plan', 'expected_outcome' => 'Explicit member only',
        ]);
        $session = ['access_mode' => 'workspace', 'current_company_id' => $organization->id, 'current_company_access_epoch' => 1, 'credential_generation' => 1];
        $this->assertTrue($project->usesScopeEight());
        $this->assertNotNull(app(ProjectExecutionAccess::class)->activeExplicitMember($owner, $project));

        $this->actingAs($reader)->withSession($session)->get(route('project-execution.show', $project))
            ->assertOk()->assertDontSee('AIと実行計画をつくる');
        $this->actingAs($reader)->withSession($session)->get(route('project-execution.ai.index', $project))->assertForbidden();
        $this->assertSame(['title', 'description'], AiProposalContract::ALLOWED_ATTRIBUTES['task']);
    }
    private function tenant(string $email): array
    {
        $owner = User::factory()->create(['email' => $email]);
        $organization = Organization::create(['name' => 'Scope 8 Org', 'slug' => 'scope-8-'.uniqid()]);
        $workspace = Workspace::create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id, 'name' => 'Execution', 'slug' => 'execution-'.uniqid(), 'status' => Workspace::STATUS_ACTIVE]);
        $organization->users()->attach($owner->id, ['role' => 'owner', 'organization_role' => 'owner', 'membership_status' => 'active', 'joined_at' => now()]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        return [$owner, $organization, $workspace];
    }

    private function join(Organization $organization, Workspace $workspace, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $organization->users()->attach($user->id, ['role' => 'member', 'organization_role' => 'member', 'membership_status' => 'active', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);
        return $user;
    }
}
