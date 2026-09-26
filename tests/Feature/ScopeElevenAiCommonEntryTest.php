<?php

namespace Tests\Feature;

use App\Contracts\AiCommonProvider;
use App\Models\AiCommonConversation;
use App\Models\AiCommonMessage;
use App\Models\AiResourcePolicy;
use App\Models\AiUsageLedger;
use App\Models\BusinessDomain;
use App\Models\Capture;
use App\Models\CompanyNotification;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\ProjectExecutionEvent;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiCommon\AiCommonAccess;
use App\Services\AiCommon\AiCommonConversationReader;
use App\Services\AiCommon\AiCommonGateway;
use App\Services\AiCommon\AiCommonPolicyWriter;
use App\Services\AiCommon\AiCommonProposalContract;
use App\Services\AiCommon\AiCommonProposalFactory;
use App\Services\AiCommon\AiCommonSourceManifest;
use App\Services\AiCommon\AiCommonUnitAdapter;
use App\Services\AiProposalApplier;
use App\Services\AiProposalApprover;
use App\Services\AiProposalUndoService;
use App\Services\BusinessDomain\BusinessDomainWriter;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ScopeElevenAiCommonEntryTest extends TestCase
{
    use RefreshDatabase;

    private Scope11FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new Scope11FakeProvider;
        $this->app->instance(AiCommonProvider::class, $this->provider);
    }

    public function test_common_entry_is_off_by_default_and_only_owner_can_enable_it(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        OrganizationAiPolicy::query()->delete();
        try {
            app(AiCommonAccess::class)->authorizeCategory($owner, $organization, OrganizationAiPolicy::CATEGORY_COMMON);
            $this->fail('Common Entry was enabled without an explicit policy.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('organization_ai_policies', 0);
        }
        try {
            app(AiCommonPolicyWriter::class)->update($member, $organization, [
                'is_enabled' => true, 'allowed_categories' => [OrganizationAiPolicy::CATEGORY_COMMON],
            ], null);
            $this->fail('A non-owner changed Organization AI Policy.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('organization_ai_policies', 0);
        }
        $policy = app(AiCommonPolicyWriter::class)->update($owner, $organization, [
            'is_enabled' => true, 'allowed_categories' => [OrganizationAiPolicy::CATEGORY_COMMON],
        ], null);
        $this->assertTrue($policy->allows(OrganizationAiPolicy::CATEGORY_COMMON));
    }

    public function test_private_conversation_cannot_be_read_by_another_member_or_tenant(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $this->expectException(AuthorizationException::class);
        app(AiCommonAccess::class)->authorizeConversation($member, $organization, $conversation);
    }

    public function test_context_requires_org_workspace_resource_and_current_permission_before_provider(): void
    {
        [$owner, , , $organization, $workspace, $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $manifest = app(AiCommonSourceManifest::class);
        try {
            $manifest->select($owner, $organization, $conversation, 'project', $project->public_id, '相談対象');
            $this->fail('Resource OFF was ignored.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('ai_common_sources', 0);
        }

        AiResourcePolicy::create([
            'organization_id' => $organization->id, 'resource_type' => 'project',
            'resource_public_id' => $project->public_id, 'allows_ai_reference' => true,
            'version' => 1, 'managed_by_user_id' => $owner->id,
        ]);
        $source = $manifest->select($owner, $organization, $conversation, 'project', $project->public_id, '相談対象');
        $this->assertStringStartsWith('src_', $source->opaque_handle);
        $this->assertArrayNotHasKey('owner_user_id', $source->projection);

        WorkspaceAiSetting::where('workspace_id', $workspace->id)->update(['enabled' => false]);
        $this->expectException(ValidationException::class);
        $manifest->authorize($owner, $organization, $source->fresh('conversation'));
    }

    public function test_source_loss_hides_dependent_answer_and_excludes_it_from_next_turn(): void
    {
        [$owner, , , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $this->resourceOn($organization, $owner, 'project', $project->public_id);
        $source = app(AiCommonSourceManifest::class)->select($owner, $organization, $conversation, 'project', $project->public_id, '計画');
        $message = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Private derived answer', 'source_fingerprint' => 'x']);
        $message->sources()->attach($source);
        $project->members()->where('user_id', $owner->id)->update(['status' => 'left']);
        $rows = app(AiCommonConversationReader::class)->visible($owner, $organization, $conversation);
        $this->assertFalse($rows[0]['visible']);
        $this->assertSame([], app(AiCommonConversationReader::class)->providerHistory($owner, $organization, $conversation));
    }

    public function test_source_reauthorization_fails_closed_after_epoch_credential_or_policy_change(): void
    {
        [$owner, , , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $this->resourceOn($organization, $owner, 'project', $project->public_id);
        $manifest = app(AiCommonSourceManifest::class);
        $source = $manifest->select($owner, $organization, $conversation, 'project', $project->public_id, 'Current permission evidence');

        OrganizationUser::query()->where('organization_id', $organization->id)
            ->where('user_id', $owner->id)->increment('access_epoch');
        try {
            $manifest->authorize($owner->fresh(), $organization, $source->fresh('conversation'));
            $this->fail('A stale membership epoch was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $source = $manifest->select($owner->fresh(), $organization, $conversation, 'project', $project->public_id, 'Refresh epoch evidence');
        $owner->increment('credential_generation');
        try {
            $manifest->authorize($owner->fresh(), $organization, $source->fresh('conversation'));
            $this->fail('A stale credential generation was accepted.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $source = $manifest->select($owner->fresh(), $organization, $conversation, 'project', $project->public_id, 'Refresh credential evidence');
        AiResourcePolicy::query()->where('organization_id', $organization->id)
            ->where('resource_type', 'project')->where('resource_public_id', $project->public_id)->increment('version');
        $this->expectException(ValidationException::class);
        $manifest->authorize($owner->fresh(), $organization, $source->fresh('conversation'));
    }

    public function test_gateway_uses_only_selected_projection_and_unknown_usage_stays_null(): void
    {
        [$owner, , , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $this->resourceOn($organization, $owner, 'project', $project->public_id);
        $source = app(AiCommonSourceManifest::class)->select($owner, $organization, $conversation, 'project', $project->public_id, '目的');
        $session = $this->productOrganizationSession($owner, $organization) + ['access_mode' => 'workspace', 'credential_generation' => 1];
        $this->actingAs($owner)->withSession($session)->post(route('ai-common.messages.store', $conversation), [
            'content' => '優先順位を整理して', 'source_ids' => [$source->id],
        ])->assertRedirect();
        $this->assertCount(1, $this->provider->calls);
        $sent = $this->provider->calls[0];
        $this->assertSame([$source->opaque_handle], array_column($sent['sources'], 'handle'));
        $this->assertArrayNotHasKey('tools', $sent);
        $ledger = AiUsageLedger::firstOrFail();
        $this->assertNull($ledger->input_tokens);
        $this->assertNull($ledger->output_tokens);
        $this->assertNull($ledger->estimated_cost_microunits);
        $this->assertDatabaseHas('ai_common_messages', ['role' => 'assistant', 'content' => 'Synthetic business answer']);
    }

    public function test_gateway_retries_once_with_a_separate_usage_attempt(): void
    {
        [$owner, , , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $this->provider->failuresRemaining = 1;

        $result = app(AiCommonGateway::class)->respond(
            $owner,
            $conversation,
            [['role' => 'user', 'content' => 'Synthetic request']],
            [],
            's11-retry-evidence',
        );

        $this->assertSame('Synthetic business answer', $result['answer']);
        $this->assertDatabaseHas('ai_usage_ledgers', [
            'logical_request_id' => 's11-retry-evidence', 'attempt' => 1,
            'result' => 'failed', 'safe_error_code' => 'provider_unavailable',
        ]);
        $this->assertDatabaseHas('ai_usage_ledgers', [
            'logical_request_id' => 's11-retry-evidence', 'attempt' => 2, 'result' => 'success',
        ]);
    }

    public function test_provider_cannot_smuggle_an_unselected_citation(): void
    {
        [$owner, , , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $this->provider->citations = ['src_forged'];
        $session = $this->productOrganizationSession($owner, $organization) + ['access_mode' => 'workspace', 'credential_generation' => 1];
        $this->actingAs($owner)->withSession($session)->post(route('ai-common.messages.store', $conversation), [
            'content' => '偽の引用を使って',
        ])->assertSessionHasErrors('citation');
        $this->assertDatabaseMissing('ai_common_messages', ['role' => 'assistant']);
    }

    public function test_capture_l1_uses_writer_is_idempotent_and_does_not_duplicate_notification(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $proposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::CAPTURE_CREATE,
            'attributes' => [
                'type' => 'request', 'body' => 'Synthetic request', 'recipient_user_id' => $member->id,
                'notification_timing' => 'specified', 'notify_at' => '2026-09-28 10:00',
                'recipient_confirmed' => true, 'jst_time_confirmed' => true,
            ],
        ]);
        app(AiProposalApprover::class)->approve($proposal, $owner);
        $first = app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
        $second = app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
        $this->assertSame('applied', $first->status);
        $this->assertSame('applied', $second->status);
        $this->assertDatabaseCount('captures', 1);
        $this->assertDatabaseCount('company_notifications', 1);
        $this->assertDatabaseCount('ai_proposal_apply_attempts', 1);
        $this->assertSame('2026-09-28 01:00:00', Capture::firstOrFail()->notify_at_utc->format('Y-m-d H:i:s'));
    }

    public function test_writer_midway_failure_rolls_back_unit_history_notification_and_proposal_state(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        $proposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::CAPTURE_CREATE,
            'attributes' => [
                'type' => 'request', 'body' => 'Rollback evidence', 'recipient_user_id' => $member->id,
                'notification_timing' => 'now', 'recipient_confirmed' => true,
            ],
        ]);
        $failing = app(Scope11FailAfterWriterUnitAdapter::class);
        $this->app->instance(AiCommonUnitAdapter::class, $failing);
        app(AiProposalApprover::class)->approve($proposal, $owner);

        try {
            app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
            $this->fail('The simulated post-Writer failure was ignored.');
        } catch (RuntimeException $error) {
            $this->assertSame('scope11_simulated_writer_boundary_failure', $error->getMessage());
        }

        $this->assertDatabaseCount('captures', 0);
        $this->assertDatabaseCount('capture_events', 0);
        $this->assertDatabaseCount('company_notifications', 0);
        $this->assertSame('approved', $proposal->fresh()->status);
        $this->assertDatabaseHas('ai_proposal_apply_attempts', ['status' => 'failed', 'error_code' => 'writer_failed']);
    }

    public function test_action_project_and_domain_adapters_use_existing_writers_and_whitelists(): void
    {
        [$owner, , , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $actionProposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::ACTION_CREATE,
            'target_public_id' => $project->public_id,
            'attributes' => ['title' => 'AI action', 'done_condition' => 'Verified', 'assigned_to' => $owner->id],
        ]);
        app(AiProposalApprover::class)->approve($actionProposal, $owner);
        app(AiProposalApplier::class)->apply($actionProposal->fresh(), $owner);
        $action = Task::where('title', 'AI action')->firstOrFail();
        $this->assertDatabaseHas('project_execution_events', ['entity_type' => 'Task', 'entity_id' => $action->id, 'event' => 'action.created']);

        $project = $project->fresh();
        $projectProposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::PROJECT_UPDATE,
            'target_public_id' => $project->public_id,
            'attributes' => ['purpose' => 'Updated purpose', 'expected_outcome' => 'Updated outcome'],
        ]);
        app(AiProposalApprover::class)->approve($projectProposal, $owner);
        app(AiProposalApplier::class)->apply($projectProposal->fresh(), $owner);
        $this->assertSame('Updated purpose', $project->fresh()->purpose);

        $domain = app(BusinessDomainWriter::class)->create($owner, $organization, ['name' => 'Core'], (string) Str::uuid());
        $domainProposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::DOMAIN_UPDATE,
            'target_public_id' => $domain->public_id,
            'attributes' => ['description' => 'Updated domain', 'reason' => 'Clarify', 'context_impact_confirmed' => true],
        ]);
        app(AiProposalApprover::class)->approve($domainProposal, $owner);
        app(AiProposalApplier::class)->apply($domainProposal->fresh(), $owner);
        $this->assertSame('Updated domain', $domain->fresh()->description);
        $this->assertDatabaseHas('business_domain_revisions', ['business_domain_id' => $domain->id, 'revision_no' => 2]);
    }

    public function test_action_l2_due_date_reason_and_review_return_use_existing_writer_contract(): void
    {
        [$owner, $member, , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $action = app(ProjectExecutionWriter::class)->createAction($owner, $project, [
            'title' => 'Review action', 'done_condition' => 'Evidence accepted',
            'assigned_to' => $owner->id, 'reviewer_user_id' => $member->id,
            'due_date' => '2026-09-28',
        ], $project->plan_version);
        $action->update(['status' => Task::STATUS_REVIEW_PENDING, 'review_status' => Task::REVIEW_PENDING]);
        $proposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::ACTION_UPDATE,
            'target_public_id' => $action->public_id,
            'attributes' => [
                'title' => 'Review action revised', 'done_condition' => 'Evidence accepted',
                'due_date' => '2026-09-29', 'reason' => 'Dependency moved',
            ],
        ]);
        app(AiProposalApprover::class)->approve($proposal, $owner);
        app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);

        $action = $action->fresh();
        $this->assertSame('2026-09-29', $action->due_date->format('Y-m-d'));
        $this->assertSame(Task::STATUS_IN_PROGRESS, $action->status);
        $this->assertSame(Task::REVIEW_REJECTED, $action->review_status);
        $this->assertSame('Dependency moved', $action->last_change_reason);
        $this->assertDatabaseHas('project_execution_events', [
            'entity_type' => 'Task', 'entity_id' => $action->id, 'event' => 'action.updated',
        ]);
    }

    public function test_field_smuggling_and_ambiguous_capture_confirmation_are_rejected(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->conversation($owner, $organization);
        try {
            $this->proposal($owner, $organization, $conversation, [
                'operation' => AiCommonProposalContract::CAPTURE_CREATE,
                'attributes' => ['type' => 'request', 'body' => 'x', 'recipient_user_id' => $member->id,
                    'notification_timing' => 'now', 'recipient_confirmed' => false],
            ]);
            $this->fail('Ambiguous recipient was accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('ai_proposals', 0);
        }

        $this->expectException(ValidationException::class);
        $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::PROJECT_UPDATE,
            'target_public_id' => 'missing',
            'attributes' => ['purpose' => 'x', 'owner_user_id' => $member->id],
        ]);
    }

    public function test_stale_project_proposal_fails_without_partial_apply(): void
    {
        [$owner, , , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $proposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::PROJECT_UPDATE,
            'target_public_id' => $project->public_id,
            'attributes' => ['purpose' => 'Proposed', 'expected_outcome' => 'Proposed'],
        ]);
        app(AiProposalApprover::class)->approve($proposal, $owner);
        $project->update(['purpose' => 'Concurrent edit']);
        try {
            app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
            $this->fail('A stale proposal was applied.');
        } catch (ValidationException) {
            $this->assertSame('Concurrent edit', $project->fresh()->purpose);
            $this->assertDatabaseMissing('ai_proposal_item_results', ['status' => 'applied']);
        }
    }

    public function test_update_only_undo_uses_the_existing_writer_and_is_idempotent(): void
    {
        [$owner, , , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $proposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::PROJECT_UPDATE,
            'target_public_id' => $project->public_id,
            'attributes' => ['purpose' => 'Applied purpose', 'expected_outcome' => 'Applied outcome'],
        ]);
        app(AiProposalApprover::class)->approve($proposal, $owner);
        app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
        $first = app(AiProposalUndoService::class)->undo($proposal->fresh(), $owner);
        $second = app(AiProposalUndoService::class)->undo($proposal->fresh(), $owner);

        $this->assertSame('applied', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('Synthetic', $project->fresh()->purpose);
        $this->assertSame('Evidence', $project->fresh()->expected_outcome);
        $this->assertDatabaseCount('ai_proposal_undos', 1);
        $this->assertDatabaseHas('project_execution_events', [
            'entity_type' => 'Project', 'entity_id' => $project->id, 'event' => 'project.updated',
        ]);
    }

    public function test_current_permission_is_rechecked_before_handoff_apply(): void
    {
        [$owner, , , $organization, , $project] = $this->tenant(true);
        $conversation = $this->conversation($owner, $organization);
        $proposal = $this->proposal($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::PROJECT_UPDATE,
            'target_public_id' => $project->public_id,
            'attributes' => ['purpose' => 'Must not apply', 'expected_outcome' => 'Must not apply'],
        ]);
        app(AiProposalApprover::class)->approve($proposal, $owner);
        $project->members()->where('user_id', $owner->id)->update(['status' => 'left']);

        try {
            app(AiProposalApplier::class)->apply($proposal->fresh(), $owner);
            $this->fail('Lost current Project permission was ignored.');
        } catch (AuthorizationException) {
            $this->assertSame('Synthetic', $project->fresh()->purpose);
            $this->assertDatabaseMissing('ai_proposal_item_results', ['status' => 'applied']);
        }
    }

    public function test_existing_project_contract_and_s9_exact_payload_remain_unchanged(): void
    {
        $this->assertSame('project-plan.v1', \App\Services\AiProposalContract::VERSION);
        $this->assertSame('project-action.v1', \App\Services\ProjectExecution\ProjectExecutionProposalContract::VERSION);
        $provider = file_get_contents(app_path('Services/ActionExecution/OpenAiActionDraftProvider.php'));
        foreach (['target', 'action_title', 'done_condition', 'instruction'] as $field) {
            $this->assertStringContainsString("'{$field}'", $provider);
        }
        $this->assertStringNotContainsString('AiCommonGateway', $provider);
    }

    private function proposal(User $actor, Organization $organization, AiCommonConversation $conversation, array $input)
    {
        return app(AiCommonProposalFactory::class)->create($actor, $organization, $conversation, $input + [
            'title' => 'Synthetic proposal', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function conversation(User $user, Organization $organization): AiCommonConversation
    {
        return AiCommonConversation::create([
            'organization_id' => $organization->id, 'user_id' => $user->id,
            'title' => 'Synthetic private conversation', 'status' => 'active', 'version' => 1,
        ]);
    }

    private function resourceOn(Organization $organization, User $owner, string $type, string $publicId): void
    {
        AiResourcePolicy::create([
            'organization_id' => $organization->id, 'resource_type' => $type,
            'resource_public_id' => $publicId, 'allows_ai_reference' => true,
            'version' => 1, 'managed_by_user_id' => $owner->id,
        ]);
    }

    private function tenant(bool $withProject = false): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $organization = Organization::create(['name' => 'S11 Synthetic', 'slug' => 's11-'.Str::lower((string) Str::ulid())]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id, 'owner_user_id' => $owner->id,
            'name' => 'S11', 'slug' => 's11-'.Str::lower((string) Str::ulid()), 'status' => Workspace::STATUS_ACTIVE,
        ]);
        foreach ([[$owner, 'owner'], [$member, 'member']] as [$user, $role]) {
            $organization->users()->attach($user->id, [
                'role' => $role, 'organization_role' => $role, 'membership_status' => 'active',
                'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now(),
            ]);
            $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
            ProductAccountEligibility::create([
                'user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE,
                'product_organization_id' => $organization->id, 'classification_version' => 's11-test',
                'classified_at' => now(), 'evidence_ref' => 's11-test',
            ]);
        }
        OrganizationAiPolicy::create([
            'organization_id' => $organization->id, 'is_enabled' => true,
            'allowed_categories' => ['common_entry', 'project', 'action', 'business_domain', 'capture'],
            'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now(),
        ]);
        WorkspaceAiSetting::create([
            'workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
            'terms_version' => WorkspaceAiSetting::TERMS_VERSION, 'enabled_by' => $owner->id, 'enabled_at' => now(),
        ]);
        if (! $withProject) {
            return [$owner, $member, $outsider, $organization, $workspace];
        }
        $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, [
            'name' => 'S11 Project', 'purpose' => 'Synthetic', 'expected_outcome' => 'Evidence',
        ]);
        app(ProjectExecutionWriter::class)->addMember($owner, $project->fresh(), $member, $workspace, ['member'], $project->fresh()->plan_version);

        return [$owner, $member, $outsider, $organization, $workspace, $project->fresh()];
    }
}

class Scope11FailAfterWriterUnitAdapter extends AiCommonUnitAdapter
{
    protected function afterWriterApply(object $target): void
    {
        throw new RuntimeException('scope11_simulated_writer_boundary_failure');
    }
}

class Scope11FakeProvider implements AiCommonProvider
{
    public array $calls = [];
    public array $citations = [];
    public int $failuresRemaining = 0;

    public function respond(array $messages, array $sources): array
    {
        $this->calls[] = compact('messages', 'sources');
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new RuntimeException('provider_unavailable');
        }

        return [
            'answer' => 'Synthetic business answer', 'citations' => $this->citations,
            'provider' => 'fake-openai', 'model' => 'fake-business',
            'input_tokens' => null, 'output_tokens' => null,
        ];
    }
}
