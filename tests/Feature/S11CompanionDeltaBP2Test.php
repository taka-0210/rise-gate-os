<?php

namespace Tests\Feature;

use App\Contracts\AiCommonProvider;
use App\Models\AiCommonMessage;
use App\Models\AiResourcePolicy;
use App\Models\CompanyNotification;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\AiCommon\AiCommonHumanMessageWriter;
use App\Services\AiCommon\AiCommonProposalContract;
use App\Services\AiCommon\AiCommonProposalFactory;
use App\Services\AiCommon\AiCommonSharedContext;
use App\Services\AiCommon\AiCommonSharedConversationReader;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use App\Services\AiCommon\AiCommonSharedCoWriter;
use App\Services\AiProposalApplier;
use App\Services\AiProposalApprover;
use App\Services\Notification\NotificationAuthorization;
use App\Services\Notification\NotificationSourceWriter;
use App\Services\ProjectExecution\ProjectExecutionWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Throwable;

class S11CompanionDeltaBP2Test extends TestCase
{
    use RefreshDatabase;

    private S11DeltaBP2Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new S11DeltaBP2Provider;
        $this->app->instance(AiCommonProvider::class, $this->provider);
    }

    public function test_shared_context_intersects_every_active_participant_and_one_co_is_server_authoritative(): void
    {
        [$owner, $member, $organization, , $project, $conversation] = $this->fixture();
        $source = app(AiCommonSharedContext::class)->select($owner, $organization, $conversation, 'project', $project->public_id, 'Shared plan context');
        $this->provider->citations = [$source->currentRevision->opaque_handle];
        $operation = (string) Str::uuid();

        $request = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
            'operation_id' => $operation, 'content' => 'Summarize the shared plan', 'source_ids' => [$source->id],
        ]);
        $replay = app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
            'operation_id' => $operation, 'content' => 'Summarize the shared plan', 'source_ids' => [$source->id],
        ]);

        $this->assertSame('answer_ready', $request->state);
        $this->assertSame($request->id, $replay->id);
        $this->assertCount(1, $this->provider->calls);
        $this->assertDatabaseCount('ai_common_shared_ai_requests', 1);
        $this->assertDatabaseHas('ai_common_shared_co_states', ['state' => 'answer_ready', 'sequence' => 1]);
        $answer = $conversation->messages()->where('role', AiCommonMessage::ROLE_ASSISTANT)->sole();
        $this->assertSame([$source->current_revision_id], $answer->sourceRevisions()->pluck('ai_common_source_revisions.id')->all());
        $this->assertTrue(collect(app(AiCommonSharedConversationReader::class)->visible($member, $organization, $conversation))->last()['visible']);
        $session = $this->productOrganizationSession($owner, $organization) + ['access_mode' => 'workspace', 'credential_generation' => 1];
        $this->actingAs($owner)->withSession($session)->get(route('ai-common.shared.show', $conversation))
            ->assertOk()->assertSee('One Shared CO: answer_ready')->assertDontSee('Continuous ASR');
    }

    public function test_lost_participant_blocks_shared_request_instead_of_narrowing_intersection(): void
    {
        [$owner, $member, $organization, , $project, $conversation] = $this->fixture();
        $source = app(AiCommonSharedContext::class)->select($owner, $organization, $conversation, 'project', $project->public_id, 'Audience loss');
        OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $member->id)
            ->update(['membership_status' => OrganizationUser::STATUS_LEFT, 'access_epoch' => 2]);

        try {
            app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
                'operation_id' => (string) Str::uuid(), 'content' => 'Must not narrow', 'source_ids' => [$source->id],
            ]);
            $this->fail('An ineligible participant was silently removed from the Shared intersection.');
        } catch (Throwable) {
            $this->assertCount(0, $this->provider->calls);
            $this->assertDatabaseCount('ai_common_shared_ai_requests', 0);
        }
    }

    public function test_retry_and_publish_reauthorize_policy_membership_and_source_revision(): void
    {
        [$owner, $member, $organization, , $project, $conversation] = $this->fixture();
        $context = app(AiCommonSharedContext::class);
        $source = $context->select($owner, $organization, $conversation, 'project', $project->public_id, 'Retry policy');
        $this->provider->failuresRemaining = 1;
        $this->provider->onRespond = function (int $attempt) use ($organization, $project): void {
            if ($attempt === 1) {
                AiResourcePolicy::query()->where('organization_id', $organization->id)->where('resource_type', 'project')->where('resource_public_id', $project->public_id)->update(['allows_ai_reference' => false, 'version' => 2]);
            }
        };
        try {
            app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'content' => 'Retry', 'source_ids' => [$source->id]]);
            $this->fail('Retry used a stale policy.');
        } catch (Throwable) {
            $this->assertCount(1, $this->provider->calls);
            $this->assertDatabaseMissing('ai_common_messages', ['role' => AiCommonMessage::ROLE_ASSISTANT]);
        }

        AiResourcePolicy::query()->where('organization_id', $organization->id)->where('resource_type', 'project')->where('resource_public_id', $project->public_id)->update(['allows_ai_reference' => true, 'version' => 3]);
        $source = $context->select($owner, $organization, $conversation, 'project', $project->public_id, 'Publish loss revision');
        $this->provider = new S11DeltaBP2Provider;
        $this->provider->onRespond = function () use ($organization, $member): void {
            OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $member->id)->update(['membership_status' => 'left', 'access_epoch' => 2]);
        };
        $this->app->instance(AiCommonProvider::class, $this->provider);
        try {
            app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'content' => 'Publish loss', 'source_ids' => [$source->id]]);
            $this->fail('A response was published after participant loss.');
        } catch (Throwable) {
            $this->assertCount(1, $this->provider->calls);
            $this->assertDatabaseMissing('ai_common_messages', ['role' => AiCommonMessage::ROLE_ASSISTANT]);
        }
    }

    public function test_unknown_shared_lineage_fails_closed_without_guessing_audience(): void
    {
        [$owner, , $organization, , $project, $conversation] = $this->fixture();
        $context = app(AiCommonSharedContext::class);
        $source = $context->select($owner, $organization, $conversation, 'project', $project->public_id, 'Initial revision');
        $revision = $source->currentRevision;
        DB::table('ai_common_shared_source_contexts')->where('ai_common_source_revision_id', $revision->id)->delete();
        $this->expectException(ValidationException::class);
        $context->authorizeRevision($owner, $organization, $revision);
    }

    public function test_reselect_during_provider_io_discards_result_instead_of_rebinding_lineage(): void
    {
        [$owner, , $organization, , $project, $conversation] = $this->fixture();
        $context = app(AiCommonSharedContext::class);
        $source = $context->select($owner, $organization, $conversation, 'project', $project->public_id, 'Provider revision');
        $providerRevision = $source->current_revision_id;
        $this->provider->onRespond = function () use ($context, $owner, $organization, $conversation, $project): void {
            $context->select($owner, $organization, $conversation, 'project', $project->public_id, 'Concurrent revision');
        };
        try {
            app(AiCommonSharedCoWriter::class)->request($owner, $organization, $conversation, [
                'operation_id' => (string) Str::uuid(), 'content' => 'Do not rebind', 'source_ids' => [$source->id],
            ]);
            $this->fail('The provider result was rebound to a newer revision.');
        } catch (Throwable) {
            $this->assertNotSame($providerRevision, $source->fresh()->current_revision_id);
            $this->assertDatabaseMissing('ai_common_messages', ['role' => AiCommonMessage::ROLE_ASSISTANT]);
            $this->assertDatabaseHas('ai_common_shared_ai_requests', ['state' => 'unavailable', 'phase' => 'discarded']);
        }
    }

    public function test_proposal_creation_requires_every_participant_target_read_permission(): void
    {
        [$owner, $member, $organization, , $project, $conversation] = $this->fixture();
        $project->update(['visibility' => Project::VISIBILITY_CONFIDENTIAL]);
        $project->members()->where('user_id', $member->id)->update(['status' => 'left']);
        $this->expectException(AuthorizationException::class);
        app(AiCommonProposalFactory::class)->create($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::ACTION_CREATE, 'target_public_id' => $project->public_id,
            'approval_recipient_user_id' => $owner->id, 'title' => 'Must fail', 'idempotency_key' => (string) Str::uuid(),
            'attributes' => ['title' => 'No leak', 'done_condition' => 'Never', 'assigned_to' => $owner->id],
        ]);
    }

    public function test_shared_proposal_reuses_existing_engine_and_unit_writer_with_audience_snapshot(): void
    {
        [$owner, $member, $organization, , $project, $conversation] = $this->fixture();
        $proposal = app(AiCommonProposalFactory::class)->create($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::ACTION_CREATE,
            'target_public_id' => $project->public_id,
            'approval_recipient_user_id' => $member->id,
            'title' => 'Shared action proposal',
            'idempotency_key' => (string) Str::uuid(),
            'attributes' => ['title' => 'Shared action', 'done_condition' => 'Verified', 'assigned_to' => $member->id],
        ]);
        $this->assertDatabaseHas('ai_common_shared_proposal_contexts', ['ai_proposal_id' => $proposal->id, 'approval_recipient_user_id' => $member->id]);
        app(AiProposalApprover::class)->approve($proposal, $member);
        $applied = app(AiProposalApplier::class)->apply($proposal->fresh(), $member);
        $replayed = app(AiProposalApplier::class)->apply($proposal->fresh(), $member);
        $this->assertSame('applied', $applied->status);
        $this->assertSame($applied->id, $replayed->id);
        $this->assertDatabaseHas('tasks', ['organization_id' => $organization->id, 'title' => 'Shared action']);
        $this->assertSame(1, Task::query()->where('organization_id', $organization->id)->where('title', 'Shared action')->count());
    }

    public function test_only_invite_explicit_mention_and_approval_create_shared_notifications_and_reauthorize(): void
    {
        [$owner, $member, $organization, , $project, $conversation] = $this->fixture();
        $notifications = app(NotificationSourceWriter::class);
        $sharedWriter = app(AiCommonSharedConversationWriter::class);
        $third = User::factory()->create();
        $this->attach($third, $organization, 'member');
        $invitation = $sharedWriter->invite($owner, $organization, $conversation, $third, ['operation_id' => (string) Str::uuid()]);
        $notifications->sharedInvitation($owner, $invitation);
        $message = app(AiCommonHumanMessageWriter::class)->postShared($owner, $organization, $conversation, ['operation_id' => (string) Str::uuid(), 'content' => 'Explicit mention only']);
        $notifications->sharedMention($owner, $conversation, $message, $member);
        $proposal = app(AiCommonProposalFactory::class)->create($owner, $organization, $conversation, [
            'operation' => AiCommonProposalContract::ACTION_CREATE, 'target_public_id' => $project->public_id,
            'approval_recipient_user_id' => $member->id, 'title' => 'Approval', 'idempotency_key' => (string) Str::uuid(),
            'attributes' => ['title' => 'Approval action', 'done_condition' => 'Done', 'assigned_to' => $member->id],
        ]);
        $notifications->sharedApprovalRequest($owner, $proposal, $member);

        $this->assertDatabaseCount('company_notifications', 3);
        foreach (CompanyNotification::query()->get() as $notification) {
            $this->assertTrue(app(NotificationAuthorization::class)->allowed($notification));
        }
        $sharedWriter->remove($owner, $organization, $conversation, $conversation->sharedConversation->participants()->where('user_id', $member->id)->firstOrFail());
        foreach (CompanyNotification::query()->where('recipient_user_id', $member->id)->get() as $notification) {
            $this->assertFalse(app(NotificationAuthorization::class)->allowed($notification));
        }
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'B P2 Synthetic', 'slug' => 'b-p2-'.Str::lower((string) Str::ulid())]);
        $this->attach($owner, $organization, 'owner');
        $this->attach($member, $organization, 'member');
        OrganizationAiPolicy::query()->create(['organization_id' => $organization->id, 'is_enabled' => true, 'allowed_categories' => ['common_entry', 'project', 'action', 'business_domain', 'capture'], 'version' => 1, 'managed_by_user_id' => $owner->id, 'confirmed_at' => now()]);
        $workspace = Workspace::query()->create(['organization_id' => $organization->id, 'owner_user_id' => $owner->id, 'name' => 'B P2', 'slug' => 'b-p2-'.Str::lower((string) Str::ulid()), 'status' => Workspace::STATUS_ACTIVE]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        WorkspaceAiSetting::query()->create(['workspace_id' => $workspace->id, 'enabled' => true, 'provider' => 'member_managed_ai', 'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES, 'terms_version' => WorkspaceAiSetting::TERMS_VERSION, 'enabled_by' => $owner->id, 'enabled_at' => now()]);
        $project = app(ProjectExecutionWriter::class)->createProject($owner, $workspace, ['name' => 'Shared Project', 'purpose' => 'P2', 'expected_outcome' => 'Verified']);
        app(ProjectExecutionWriter::class)->addMember($owner, $project->fresh(), $member, $workspace, ['member'], $project->fresh()->plan_version);
        AiResourcePolicy::query()->create(['organization_id' => $organization->id, 'resource_type' => 'project', 'resource_public_id' => $project->public_id, 'allows_ai_reference' => true, 'version' => 1, 'managed_by_user_id' => $owner->id]);
        $writer = app(AiCommonSharedConversationWriter::class);
        $conversation = $writer->create($owner, $organization, ['operation_id' => (string) Str::uuid(), 'name' => 'P2 Shared', 'purpose' => 'Shared purpose']);
        $invitation = $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
        $writer->accept($member, $organization, $invitation);

        return [$owner, $member, $organization, $workspace, $project->fresh(), $conversation->fresh()];
    }

    private function attach(User $user, Organization $organization, string $role): void
    {
        $organization->users()->attach($user->id, ['role' => $role, 'organization_role' => $role, 'membership_status' => OrganizationUser::STATUS_ACTIVE, 'access_epoch' => 1, 'lifecycle_version' => 1, 'joined_at' => now()]);
        ProductAccountEligibility::query()->create(['user_id' => $user->id, 'mode' => ProductAccountEligibility::MODE_SINGLE, 'product_organization_id' => $organization->id, 'classification_version' => 'b-p2-test', 'classified_at' => now(), 'evidence_ref' => 'b-p2-test']);
    }
}

class S11DeltaBP2Provider implements AiCommonProvider
{
    public array $calls = [];

    public array $citations = [];

    public int $failuresRemaining = 0;

    public $onRespond = null;

    public function respond(array $messages, array $sources): array
    {
        $this->calls[] = compact('messages', 'sources');
        if ($this->onRespond) {
            ($this->onRespond)(count($this->calls));
        }
        if ($this->failuresRemaining-- > 0) {
            throw new \RuntimeException('provider_unavailable');
        }

        return ['answer' => 'Shared synthetic answer', 'citations' => $this->citations, 'provider' => 'fake', 'model' => 'shared', 'input_tokens' => null, 'output_tokens' => null];
    }
}
