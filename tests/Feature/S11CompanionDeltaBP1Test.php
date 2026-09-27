<?php

namespace Tests\Feature;

use App\Contracts\AiCommonProvider;
use App\Models\AiCommonConversation;
use App\Models\AiCommonSharedParticipant;
use App\Models\Organization;
use App\Models\OrganizationAiPolicy;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\User;
use App\Services\AiCommon\AiCommonAccess;
use App\Services\AiCommon\AiCommonHumanMessageWriter;
use App\Services\AiCommon\AiCommonSharedAccess;
use App\Services\AiCommon\AiCommonSharedConversationWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class S11CompanionDeltaBP1Test extends TestCase
{
    use RefreshDatabase;

    private S11DeltaBP1Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new S11DeltaBP1Provider;
        $this->app->instance(AiCommonProvider::class, $this->provider);
    }

    public function test_shared_creation_is_additive_idempotent_and_available_while_ai_is_off(): void
    {
        [$owner, , , $organization] = $this->tenant();
        OrganizationAiPolicy::query()->where('organization_id', $organization->id)
            ->update(['is_enabled' => false, 'version' => 2]);
        $operationId = (string) Str::uuid();
        $input = ['operation_id' => $operationId, 'name' => 'Synthetic room', 'purpose' => 'Align the synthetic team'];
        $writer = app(AiCommonSharedConversationWriter::class);

        $conversation = $writer->create($owner, $organization, $input);
        $replayed = $writer->create($owner, $organization, $input);

        $this->assertSame($conversation->id, $replayed->id);
        $this->assertSame(AiCommonConversation::KIND_SHARED, $conversation->conversation_kind);
        $this->assertDatabaseCount('ai_common_shared_conversations', 1);
        $this->assertDatabaseHas('ai_common_shared_participants', [
            'user_id' => $owner->id,
            'role' => AiCommonSharedParticipant::ROLE_OWNER,
            'status' => AiCommonSharedParticipant::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseHas('ai_common_shared_purpose_revisions', [
            'revision_no' => 1,
            'purpose' => 'Align the synthetic team',
        ]);
        $this->assertDatabaseCount('ai_common_shared_operations', 1);
        $this->assertSame(0, $this->provider->calls);
        $this->expectException(AuthorizationException::class);
        app(AiCommonAccess::class)->authorizeConversation($owner, $organization, $conversation);
    }

    public function test_invitation_hides_content_until_same_org_user_accepts(): void
    {
        [$owner, $member, $admin, $organization] = $this->tenant();
        $conversation = $this->shared($owner, $organization);
        app(AiCommonHumanMessageWriter::class)->postShared($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(),
            'content' => 'Private until accepted',
        ]);
        $invitation = app(AiCommonSharedConversationWriter::class)->invite(
            $owner,
            $organization,
            $conversation,
            $member,
            ['operation_id' => (string) Str::uuid()],
        );

        foreach ([$member, $admin] as $nonParticipant) {
            try {
                app(AiCommonSharedAccess::class)->authorizeParticipant($nonParticipant, $organization, $conversation);
                $this->fail('A non-participant obtained Shared Conversation access.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }

        app(AiCommonSharedConversationWriter::class)->accept($member, $organization, $invitation);
        $participant = app(AiCommonSharedAccess::class)->authorizeParticipant($member, $organization, $conversation);
        $this->assertSame(AiCommonSharedParticipant::STATUS_ACTIVE, $participant->status);
        $this->assertSame($member->id, $participant->user_id);
        $this->assertDatabaseCount('ai_common_messages', 1);
    }

    public function test_inactive_suspended_left_and_cross_org_users_fail_closed(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->shared($owner, $organization);
        $otherOrg = Organization::query()->create(['name' => 'Other', 'slug' => 'other-'.Str::lower((string) Str::ulid())]);
        $outsider = User::factory()->create();
        $this->attachEligible($outsider, $otherOrg, 'owner');
        $writer = app(AiCommonSharedConversationWriter::class);

        foreach ([
            OrganizationUser::STATUS_SUSPENDED,
            OrganizationUser::STATUS_LEFT,
        ] as $status) {
            OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $member->id)
                ->update(['membership_status' => $status, 'access_epoch' => 2]);
            try {
                $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
                $this->fail('An ineligible user was invited.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
        $member->update(['is_active' => false]);
        OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $member->id)
            ->update(['membership_status' => OrganizationUser::STATUS_ACTIVE]);
        try {
            $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
            $this->fail('An inactive user was invited.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $this->expectException(AuthorizationException::class);
        $writer->invite($owner, $organization, $conversation, $outsider, ['operation_id' => (string) Str::uuid()]);
    }

    public function test_shared_human_writer_records_author_is_idempotent_and_never_calls_provider(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->shared($owner, $organization);
        $invitation = app(AiCommonSharedConversationWriter::class)->invite(
            $owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]
        );
        app(AiCommonSharedConversationWriter::class)->accept($member, $organization, $invitation);
        $operationId = (string) Str::uuid();
        $input = ['operation_id' => $operationId, 'content' => 'Human-only shared post'];

        $message = app(AiCommonHumanMessageWriter::class)->postShared($member, $organization, $conversation, $input);
        $replayed = app(AiCommonHumanMessageWriter::class)->postShared($member, $organization, $conversation, $input);

        $this->assertSame($message->id, $replayed->id);
        $this->assertDatabaseHas('ai_common_shared_message_authors', [
            'ai_common_message_id' => $message->id,
            'author_user_id' => $member->id,
            'participant_audience_epoch' => 1,
        ]);
        $this->assertDatabaseCount('ai_common_input_operations', 1);
        $this->assertDatabaseCount('ai_usage_ledgers', 0);
        $this->assertDatabaseCount('ai_proposals', 0);
        $this->assertSame(0, $this->provider->calls);
    }

    public function test_remove_leave_and_owner_transfer_preserve_owner_protection(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->shared($owner, $organization);
        $writer = app(AiCommonSharedConversationWriter::class);
        $invitation = $writer->invite($owner, $organization, $conversation, $member, ['operation_id' => (string) Str::uuid()]);
        $memberParticipant = $writer->accept($member, $organization, $invitation);

        try {
            $writer->leave($owner, $organization, $conversation);
            $this->fail('Owner left without a successor.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $ownerParticipant = $conversation->sharedConversation->participants()->where('user_id', $owner->id)->firstOrFail();
        try {
            $writer->remove($owner, $organization, $conversation, $ownerParticipant);
            $this->fail('Owner was removed.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $writer->requestOwnerTransfer($owner, $organization, $conversation, $memberParticipant);
        $this->assertSame($owner->id, $conversation->sharedConversation->fresh()->owner_user_id);
        $writer->acceptOwnerTransfer($member, $organization, $conversation);
        $this->assertSame($member->id, $conversation->sharedConversation->fresh()->owner_user_id);
        $writer->leave($owner, $organization, $conversation);
        $this->assertDatabaseHas('ai_common_shared_participants', [
            'user_id' => $owner->id,
            'status' => AiCommonSharedParticipant::STATUS_LEFT,
        ]);
    }

    public function test_purpose_revisions_are_immutable_and_archive_blocks_changes(): void
    {
        [$owner, $member, , $organization] = $this->tenant();
        $conversation = $this->shared($owner, $organization);
        $writer = app(AiCommonSharedConversationWriter::class);
        $writer->updateIdentity($owner, $organization, $conversation, [
            'operation_id' => (string) Str::uuid(),
            'name' => 'Renamed room',
            'purpose' => 'Second immutable purpose',
        ]);

        $this->assertDatabaseHas('ai_common_shared_purpose_revisions', ['revision_no' => 1, 'purpose' => 'Initial purpose']);
        $this->assertDatabaseHas('ai_common_shared_purpose_revisions', ['revision_no' => 2, 'purpose' => 'Second immutable purpose']);
        try {
            $writer->updateIdentity($member, $organization, $conversation, [
                'operation_id' => (string) Str::uuid(),
                'name' => 'Unauthorized',
                'purpose' => 'Unauthorized',
            ]);
            $this->fail('A non-owner changed Purpose.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }

        $writer->archive($owner, $organization, $conversation);
        $this->expectException(ValidationException::class);
        app(AiCommonHumanMessageWriter::class)->postShared($owner, $organization, $conversation->fresh(), [
            'operation_id' => (string) Str::uuid(),
            'content' => 'Must not append',
        ]);
    }

    public function test_shared_conversation_enforces_owner_inclusive_ten_person_limit(): void
    {
        [$owner, , , $organization] = $this->tenant();
        $conversation = $this->shared($owner, $organization);
        $writer = app(AiCommonSharedConversationWriter::class);
        for ($index = 0; $index < 9; $index++) {
            $user = User::factory()->create();
            $this->attachEligible($user, $organization, 'member');
            $writer->invite($owner, $organization, $conversation, $user, ['operation_id' => (string) Str::uuid()]);
        }
        $eleventh = User::factory()->create();
        $this->attachEligible($eleventh, $organization, 'member');

        $this->expectException(ValidationException::class);
        $writer->invite($owner, $organization, $conversation, $eleventh, ['operation_id' => (string) Str::uuid()]);
    }

    private function shared(User $owner, Organization $organization): AiCommonConversation
    {
        return app(AiCommonSharedConversationWriter::class)->create($owner, $organization, [
            'operation_id' => (string) Str::uuid(),
            'name' => 'B P1 Shared',
            'purpose' => 'Initial purpose',
        ]);
    }

    private function tenant(): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $admin = User::factory()->create(['is_system_admin' => true]);
        $organization = Organization::query()->create([
            'name' => 'Delta B P1 Synthetic',
            'slug' => 'delta-b-p1-'.Str::lower((string) Str::ulid()),
        ]);
        $this->attachEligible($owner, $organization, 'owner');
        $this->attachEligible($member, $organization, 'member');
        $this->attachEligible($admin, $organization, 'admin');
        OrganizationAiPolicy::query()->create([
            'organization_id' => $organization->id,
            'is_enabled' => true,
            'allowed_categories' => ['common_entry'],
            'version' => 1,
            'managed_by_user_id' => $owner->id,
            'confirmed_at' => now(),
        ]);

        return [$owner, $member, $admin, $organization];
    }

    private function attachEligible(User $user, Organization $organization, string $role): void
    {
        $organization->users()->attach($user->id, [
            'role' => $role === 'owner' ? 'owner' : 'member',
            'organization_role' => $role,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1,
            'lifecycle_version' => 1,
            'joined_at' => now(),
        ]);
        ProductAccountEligibility::query()->create([
            'user_id' => $user->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $organization->id,
            'classification_version' => 'delta-b-p1-test',
            'classified_at' => now(),
            'evidence_ref' => 'delta-b-p1-test',
        ]);
    }
}

class S11DeltaBP1Provider implements AiCommonProvider
{
    public int $calls = 0;

    public function respond(array $messages, array $sources): array
    {
        $this->calls++;

        return [
            'answer' => 'Provider must not be called by a Shared Human Message.',
            'citations' => [],
            'provider' => 'unexpected',
            'model' => 'unexpected',
            'input_tokens' => null,
            'output_tokens' => null,
        ];
    }
}
