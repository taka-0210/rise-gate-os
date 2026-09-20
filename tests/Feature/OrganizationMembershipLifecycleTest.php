<?php

namespace Tests\Feature;

use App\Jobs\SendOrganizationInvitationMail;
use App\Mail\AccountActionMail;
use App\Models\AiAccessKey;
use App\Models\Improvement;
use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\OrganizationGroupMembership;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembershipLifecycleOperation;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Roadmap;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Services\Organization\OrganizationAudit;
use App\Services\Organization\OrganizationInvitationMailer;
use App\Services\Organization\OrganizationMembershipLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OrganizationMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_five_migration_is_additive_rerunnable_and_reversible_without_losing_membership(): void
    {
        $organization = $this->organization('migration');
        [, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);

        $this->assertTrue(Schema::hasColumns('organization_users', [
            'access_epoch',
            'lifecycle_version',
            'status_changed_at',
            'status_changed_by_user_id',
            'status_change_reason',
        ]));
        $this->assertTrue(Schema::hasTable('organization_membership_lifecycle_operations'));
        $this->assertSame(1, $membership->fresh()->access_epoch);
        $this->assertSame(1, $membership->fresh()->lifecycle_version);

        $migration = require database_path('migrations/2026_09_20_000004_add_scope_five_membership_lifecycle.php');
        $migration->up();
        $this->assertDatabaseHas('organization_users', ['id' => $membership->id]);

        $migration->down();
        $this->assertFalse(Schema::hasTable('organization_membership_lifecycle_operations'));
        $this->assertDatabaseHas('organization_users', ['id' => $membership->id]);

        $migration->up();
        $this->assertTrue(Schema::hasTable('organization_membership_lifecycle_operations'));
        $this->assertDatabaseHas('organization_users', [
            'id' => $membership->id,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'access_epoch' => 1,
            'lifecycle_version' => 1,
        ]);
    }

    public function test_actor_matrix_transitions_self_guard_and_left_terminal_state_are_enforced(): void
    {
        $organization = $this->organization('matrix');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$secondOwner, $secondOwnerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        [$member, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        [, $invitedMembership] = $this->member(
            $organization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            status: OrganizationUser::STATUS_INVITED,
        );

        $this->lifecycle($admin, $organization, $membership, 'suspend', 1, 'admin-suspend');
        $membership->refresh();
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $membership->membership_status);
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_MEMBER, $membership->organization_role);

        $this->lifecycle($owner, $organization, $membership, 'resume', 2, 'owner-resume');
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $membership->fresh()->membership_status);

        try {
            $this->lifecycle($admin, $organization, $secondOwnerMembership, 'suspend', 1, 'admin-owner');
            $this->fail('Admin must not operate on an Owner.');
        } catch (AuthorizationException) {
            $this->assertSame(OrganizationUser::STATUS_ACTIVE, $secondOwnerMembership->fresh()->membership_status);
        }

        try {
            $this->lifecycle($member, $organization, $ownerMembership, 'suspend', 1, 'member-owner');
            $this->fail('Member must not manage lifecycle.');
        } catch (AuthorizationException) {
            $this->assertSame(OrganizationUser::STATUS_ACTIVE, $ownerMembership->fresh()->membership_status);
        }

        try {
            $this->lifecycle($owner, $organization, $ownerMembership, 'suspend', 1, 'self');
            $this->fail('Self lifecycle operation must be denied.');
        } catch (AuthorizationException) {
            $this->assertSame(OrganizationUser::STATUS_ACTIVE, $ownerMembership->fresh()->membership_status);
        }

        $this->lifecycle($owner, $organization, $membership->fresh(), 'end', 3, 'owner-end');
        $this->assertSame(OrganizationUser::STATUS_LEFT, $membership->fresh()->membership_status);
        try {
            $this->lifecycle($owner, $organization, $membership->fresh(), 'resume', 4, 'left-resume');
            $this->fail('A left membership must not resume in Scope 5.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('command', $exception->errors());
        }
        foreach (['suspend', 'end', 'resume'] as $command) {
            try {
                $this->lifecycle($owner, $organization, $invitedMembership, $command, 1, 'invited-'.$command);
                $this->fail('An invited membership is owned by Scope 4 only.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('command', $exception->errors());
            }
        }

        $this->assertDatabaseHas('organization_audit_events', [
            'event' => 'organization.membership.end',
            'outcome' => OrganizationAuditEvent::OUTCOME_SUCCESS,
            'subject_user_id' => $member->id,
        ]);
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $secondOwnerMembership->fresh()->membership_status);
        $this->assertSame($secondOwner->id, $secondOwnerMembership->user_id);
    }

    public function test_http_actor_matrix_and_stale_role_recheck_match_the_service_contract(): void
    {
        $organization = $this->organization('http-matrix');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$secondOwner, $secondOwnerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin, $adminMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        [$member, $memberMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);

        $this->actingAs($admin)
            ->withSession($this->companySession($organization))
            ->get(route('organization-management.index'))
            ->assertOk()
            ->assertSee(route('organization-management.memberships.lifecycle', $memberMembership), false)
            ->assertDontSee(route('organization-management.memberships.lifecycle', $ownerMembership), false)
            ->assertDontSee(route('organization-management.memberships.lifecycle', $secondOwnerMembership), false);

        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->patch(route('organization-management.memberships.lifecycle', $adminMembership), [
                'command' => 'suspend',
                'reason' => 'Owner may suspend another Admin',
                'expected_version' => 1,
                'request_id' => 'http-owner-admin',
            ])->assertRedirect();
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $adminMembership->fresh()->membership_status);
        $this->lifecycle($owner, $organization, $adminMembership->fresh(), 'resume', 2, 'http-owner-admin-resume');

        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->patch(route('organization-management.memberships.lifecycle', $secondOwnerMembership), [
                'command' => 'suspend',
                'reason' => 'Owner may suspend another Owner',
                'expected_version' => 1,
                'request_id' => 'http-owner-owner',
            ])->assertRedirect();
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $secondOwnerMembership->fresh()->membership_status);
        $this->lifecycle($owner, $organization, $secondOwnerMembership->fresh(), 'resume', 2, 'http-owner-owner-resume');

        $this->actingAs($admin)
            ->withSession($this->companySession($organization, 3))
            ->patch(route('organization-management.memberships.lifecycle', $memberMembership), [
                'command' => 'suspend',
                'reason' => 'Admin may suspend a Member',
                'expected_version' => 1,
                'request_id' => 'http-admin-member',
            ])->assertRedirect();
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $memberMembership->fresh()->membership_status);

        $this->actingAs($admin)
            ->withSession($this->companySession($organization, 3))
            ->patch(route('organization-management.memberships.lifecycle', $secondOwnerMembership), [
                'command' => 'suspend',
                'reason' => 'Admin may not suspend an Owner',
                'expected_version' => 1,
                'request_id' => 'http-admin-owner',
            ])->assertForbidden();
        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->patch(route('organization-management.memberships.lifecycle', $ownerMembership), [
                'command' => 'suspend',
                'reason' => 'Self operation is forbidden',
                'expected_version' => 1,
                'request_id' => 'http-self',
            ])->assertForbidden();
        $this->actingAs($member)
            ->withSession($this->companySession($organization, 2))
            ->patch(route('organization-management.memberships.lifecycle', $adminMembership), [
                'command' => 'suspend',
                'reason' => 'Member may not operate lifecycle',
                'expected_version' => 3,
                'request_id' => 'http-member',
            ])->assertRedirect(route('companies.index'));

        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->put(route('organization-management.memberships.role', $adminMembership), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            ])->assertRedirect();
        $this->actingAs($admin)
            ->withSession($this->companySession($organization, 3))
            ->patch(route('organization-management.memberships.lifecycle', $memberMembership), [
                'command' => 'resume',
                'reason' => 'Stale Admin session must be rechecked',
                'expected_version' => 2,
                'request_id' => 'http-stale-role',
            ])->assertForbidden();
    }

    public function test_last_active_owner_and_global_account_writer_invariants_are_preserved(): void
    {
        $organization = $this->organization('owner-guard');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$admin] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN);
        $pendingOwnerInvitation = $this->invitation($organization, $owner, 'pending-owner@example.test');
        $pendingOwnerInvitation->update([
            'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
        ]);

        try {
            $this->lifecycle($admin, $organization, $ownerMembership, 'suspend', 1, 'last-owner');
            $this->fail('Last active Owner must be preserved.');
        } catch (AuthorizationException|ValidationException) {
            $this->assertSame(OrganizationUser::STATUS_ACTIVE, $ownerMembership->fresh()->membership_status);
        }

        $systemAdmin = User::factory()->create(['is_system_admin' => true]);
        $this->actingAs($systemAdmin)
            ->withSession(['access_mode' => 'system_admin'])
            ->from(route('system-admin.members.edit', $owner))
            ->put(route('system-admin.members.update', $owner), [
                'name' => $owner->name,
                'email' => $owner->email,
                'is_system_admin' => '0',
                'is_active' => '0',
            ])
            ->assertSessionHasErrors('is_active');
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $pendingOwnerInvitation->fresh()->status);

        [$otherOwner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->actingAs($systemAdmin)
            ->withSession(['access_mode' => 'system_admin'])
            ->put(route('system-admin.members.update', $owner), [
                'name' => $owner->name,
                'email' => $owner->email,
                'is_system_admin' => '0',
                'is_active' => '0',
            ])
            ->assertRedirect();
        $this->assertFalse($owner->fresh()->is_active);
        $this->assertTrue($otherOwner->fresh()->is_active);
    }

    public function test_idempotency_optimistic_lock_epoch_and_atomic_audit_are_enforced(): void
    {
        $organization = $this->organization('concurrency');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);

        $operation = $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'same-request');
        $retry = $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'same-request');
        $this->assertSame($operation->id, $retry->id);
        $this->assertSame(1, OrganizationMembershipLifecycleOperation::query()->count());
        $this->assertSame(2, $membership->fresh()->lifecycle_version);
        $this->assertSame(2, $membership->fresh()->access_epoch);

        try {
            $this->lifecycle($owner, $organization, $membership->fresh(), 'suspend', 1, 'stale-request');
            $this->fail('A stale version must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expected_version', $exception->errors());
        }
        $this->assertDatabaseHas('organization_audit_events', [
            'organization_id' => $organization->id,
            'event' => 'organization.membership.suspend',
            'outcome' => OrganizationAuditEvent::OUTCOME_CONFLICT,
        ]);

        try {
            $this->lifecycle($owner, $organization, $membership->fresh(), 'resume', 2, 'same-request', 'different payload');
            $this->fail('A reused request id with another payload must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_id', $exception->errors());
        }

        $this->lifecycle($owner, $organization, $membership->fresh(), 'resume', 2, 'resume-request');
        $membership->refresh();
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $membership->membership_status);
        $this->assertSame(3, $membership->access_epoch);
        $oldRetryAfterResume = $this->lifecycle(
            $owner,
            $organization,
            $membership,
            'suspend',
            1,
            'same-request',
        );
        $this->assertSame($operation->id, $oldRetryAfterResume->id);
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $membership->fresh()->membership_status);
        $this->assertSame(3, $membership->fresh()->lifecycle_version);

        $workspace = $this->workspace($organization, $owner, 'rollback');
        $workspace->users()->attach($membership->user_id, ['role' => 'member', 'joined_at' => now()]);
        $key = $this->aiKey($workspace, $membership->user, 'rollback');
        $invitation = $this->invitation($organization, $membership->user, 'rollback@example.test');
        $audit = Mockery::mock(OrganizationAudit::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(OrganizationAudit::class, $audit);
        try {
            $this->lifecycle($owner, $organization, $membership, 'suspend', 3, 'audit-failure');
            $this->fail('Audit failure must roll back the lifecycle mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $membership->fresh()->membership_status);
        $this->assertSame(3, $membership->fresh()->lifecycle_version);
        $this->assertNull($key->fresh()->revoked_at);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $invitation->fresh()->status);
        $this->assertSame(1, $invitation->fresh()->token_generation);
        $this->assertDatabaseMissing('organization_membership_lifecycle_operations', [
            'request_id' => 'audit-failure',
        ]);
    }

    public function test_stop_revokes_only_target_org_credentials_and_preserves_all_business_relations(): void
    {
        Mail::fake();
        $organization = $this->organization('credential-a');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        [$colleague] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $workspace = $this->workspace($organization, $owner, 'a');
        $workspace->users()->attach($target->id, ['role' => 'member', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $target->id,
            'name' => 'Retained Project',
        ]);
        $improvement = Improvement::create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'title' => 'Retained Improvement',
            'proposed_by' => $target->id,
            'assigned_to' => $target->id,
        ]);
        $task = Task::create([
            'organization_id' => $organization->id,
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'improvement_id' => $improvement->id,
            'title' => 'Retained Task',
            'assigned_to' => $target->id,
            'created_by' => $target->id,
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $target->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_VIEWER,
            'permission_level' => ProjectMember::PERMISSION_VIEW,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Retained Group']);
        OrganizationGroupMembership::create([
            'organization_group_id' => $group->id,
            'organization_user_id' => $membership->id,
            'added_by' => $owner->id,
        ]);

        $otherOrganization = $this->organization('credential-b');
        [, $otherMembership] = $this->member($otherOrganization, OrganizationUser::ORGANIZATION_ROLE_MEMBER, $target);
        [$otherOwner] = $this->member($otherOrganization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $otherWorkspace = $this->workspace($otherOrganization, $otherOwner, 'b');
        $otherWorkspace->users()->attach($target->id, ['role' => 'member', 'joined_at' => now()]);

        $key = $this->aiKey($workspace, $target, 'target-a');
        $colleagueKey = $this->aiKey($workspace, $colleague, 'colleague-a');
        $otherKey = $this->aiKey($otherWorkspace, $target, 'target-b');
        $sponsored = $this->invitation($organization, $target, 'new-staff@example.test');
        $forTarget = $this->invitation($organization, $owner, $target->email, $target, $membership);
        $unrelated = $this->invitation($organization, $owner, 'unrelated@example.test');
        $otherInvitation = $this->invitation($otherOrganization, $otherOwner, $target->email, $target, $otherMembership);
        $globalBefore = $target->only([
            'email', 'password', 'is_active', 'credential_generation', 'remember_token',
        ]);

        $operation = $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'revoke-target');
        $this->assertSame(1, $operation->revoked_ai_key_count);
        $this->assertSame(2, $operation->revoked_invitation_count);
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertNull($colleagueKey->fresh()->revoked_at);
        $this->assertNull($otherKey->fresh()->revoked_at);
        $this->assertSame(OrganizationInvitation::STATUS_REVOKED, $sponsored->fresh()->status);
        $this->assertSame(OrganizationInvitation::STATUS_REVOKED, $forTarget->fresh()->status);
        $this->assertSame(2, $sponsored->fresh()->token_generation);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $unrelated->fresh()->status);
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $otherInvitation->fresh()->status);
        $this->assertSame($globalBefore, $target->fresh()->only(array_keys($globalBefore)));
        $auditPayload = json_encode(
            OrganizationAuditEvent::query()
                ->where('event', 'organization.membership.suspend')
                ->latest('id')
                ->firstOrFail()
                ->toArray(),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString($target->email, $auditPayload);
        $this->assertStringNotContainsString('scope-five-invitation-token', $auditPayload);
        $this->assertStringNotContainsString('token-target-a', $auditPayload);
        $this->assertStringNotContainsString((string) $target->password, $auditPayload);
        $claimUrl = route('invitations.claim', $sponsored->public_id)
            .'?token=scope-five-invitation-token';
        $this->get($claimUrl)->assertSessionHasErrors('invitation');

        $this->assertDatabaseHas('organization_group_memberships', ['organization_user_id' => $membership->id]);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $target->id]);
        $this->assertDatabaseHas('project_members', ['project_id' => $project->id, 'user_id' => $target->id]);
        $this->assertSame($target->id, $project->fresh()->owner_user_id);
        $this->assertSame($target->id, $improvement->fresh()->proposed_by);
        $this->assertSame($target->id, $improvement->fresh()->assigned_to);
        $this->assertSame($target->id, $task->fresh()->created_by);
        $this->assertSame($target->id, $task->fresh()->assigned_to);
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $otherMembership->fresh()->membership_status);

        $this->lifecycle($owner, $organization, $membership->fresh(), 'resume', 2, 'resume-target');
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $membership->fresh()->membership_status);
        $this->assertNotNull($key->fresh()->revoked_at);
        $this->assertSame(OrganizationInvitation::STATUS_REVOKED, $forTarget->fresh()->status);
        $this->assertSame(1, $target->workspaces()->where('workspaces.organization_id', $organization->id)->count());

        $revokedJob = new SendOrganizationInvitationMail(
            $sponsored->id,
            1,
            'scope-five-invitation-token',
            'array',
        );
        $revokedJob->handle(app(OrganizationInvitationMailer::class));
        Mail::assertNothingSent();
        $this->get($claimUrl)->assertSessionHasErrors('invitation');
        $otherJob = new SendOrganizationInvitationMail(
            $otherInvitation->id,
            1,
            'scope-five-invitation-token',
            'array',
        );
        $otherJob->handle(app(OrganizationInvitationMailer::class));
        Mail::assertSent(AccountActionMail::class, 1);
    }

    public function test_invitation_derivative_revoke_uses_current_verified_identity_not_stale_email_guessing(): void
    {
        $organization = $this->organization('invitation-identity');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $oldEmail = $target->email;
        $staleEmailInvitation = $this->invitation($organization, $owner, $oldEmail);

        $target->forceFill([
            'email' => 'current-'.Str::lower((string) Str::ulid()).'@example.test',
            'email_verified_at' => now(),
        ])->save();
        $currentIdentityInvitation = $this->invitation(
            $organization,
            $owner,
            $target->email,
            $target,
            $membership,
        );

        $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'identity-stop');
        $this->assertSame(OrganizationInvitation::STATUS_PENDING, $staleEmailInvitation->fresh()->status);
        $this->assertSame(OrganizationInvitation::STATUS_REVOKED, $currentIdentityInvitation->fresh()->status);
    }

    public function test_two_org_access_is_isolated_and_old_epoch_cannot_revive_after_resume(): void
    {
        $first = $this->organization('session-a');
        [$owner] = $this->member($first, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($first, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $firstWorkspace = $this->workspace($first, $owner, 'session-a');
        $firstWorkspace->users()->attach($target->id, ['role' => 'member', 'joined_at' => now()]);
        $firstProject = Project::create([
            'organization_id' => $first->id,
            'owning_workspace_id' => $firstWorkspace->id,
            'billing_workspace_id' => $firstWorkspace->id,
            'owner_user_id' => $target->id,
            'name' => 'Stopped Organization Project',
        ]);
        ProjectMember::create([
            'project_id' => $firstProject->id,
            'user_id' => $target->id,
            'workspace_id' => $firstWorkspace->id,
            'project_role' => ProjectMember::ROLE_VIEWER,
            'permission_level' => ProjectMember::PERMISSION_VIEW,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $firstKey = $this->aiKey($firstWorkspace, $target, 'session-a');

        $second = $this->organization('session-b');
        [$secondOwner] = $this->member($second, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->member($second, OrganizationUser::ORGANIZATION_ROLE_MEMBER, $target);
        $secondWorkspace = $this->workspace($second, $secondOwner, 'session-b');
        $secondWorkspace->users()->attach($target->id, ['role' => 'member', 'joined_at' => now()]);
        $secondKey = $this->aiKey($secondWorkspace, $target, 'session-b');

        $this->lifecycle($owner, $first, $membership, 'suspend', 1, 'session-stop');

        $this->actingAs($target)
            ->withSession($this->companySession($first))
            ->get(route('company.home'))
            ->assertOk()
            ->assertSessionHas('current_company_id', $second->id)
            ->assertSessionHas('current_company_access_epoch', 1);
        $this->get(route('account.profile'))
            ->assertOk()
            ->assertSee('この会社での利用は一時停止中です。');
        $this->get(route('projects.show', $firstProject))->assertForbidden();
        $this->post(route('companies.switch', $first))->assertForbidden();
        $this->withToken('token-session-a')
            ->getJson(route('api.ai.projects.index'))
            ->assertUnauthorized();
        $this->withToken('token-session-b')
            ->getJson(route('api.ai.projects.index'))
            ->assertOk();
        $this->assertNotNull($firstKey->fresh()->revoked_at);
        $this->assertNull($secondKey->fresh()->revoked_at);

        $this->post(route('logout'))->assertRedirect();
        $this->post(route('login'), [
            'email' => $target->email,
            'password' => 'password',
        ])->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $second->id);

        $this->lifecycle($owner, $first, $membership->fresh(), 'resume', 2, 'session-resume');
        $this->actingAs($target)
            ->withSession($this->companySession($first, 1))
            ->get(route('company.home'))
            ->assertRedirect(route('companies.index'))
            ->assertSessionMissing('current_company_id')
            ->assertSessionMissing('current_workspace_id')
            ->assertSessionMissing('current_company_access_epoch');

        $this->actingAs($target)
            ->post(route('companies.switch', $first))
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $first->id)
            ->assertSessionHas('current_company_access_epoch', 3);
    }

    public function test_cross_organization_project_access_follows_the_project_members_own_workspace_membership(): void
    {
        $projectOrganization = $this->organization('cross-project-owner');
        [$projectOwner] = $this->member($projectOrganization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $projectWorkspace = $this->workspace($projectOrganization, $projectOwner, 'cross-project-owner');

        $memberOrganization = $this->organization('cross-project-member');
        [$memberOrganizationOwner] = $this->member($memberOrganization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$externalMember, $externalOrganizationMembership] = $this->member(
            $memberOrganization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
        );
        $memberWorkspace = $this->workspace($memberOrganization, $memberOrganizationOwner, 'cross-project-member');
        $memberWorkspace->users()->attach($externalMember->id, ['role' => 'member', 'joined_at' => now()]);

        $project = Project::create([
            'organization_id' => $projectOrganization->id,
            'owning_workspace_id' => $projectWorkspace->id,
            'billing_workspace_id' => $projectWorkspace->id,
            'owner_user_id' => $projectOwner->id,
            'name' => 'Cross Organization Project',
        ]);
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $externalMember->id,
            'workspace_id' => $memberWorkspace->id,
            'project_role' => ProjectMember::ROLE_CODER,
            'permission_level' => ProjectMember::PERMISSION_EDIT,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);
        $roadmap = Roadmap::create([
            'organization_id' => $projectOrganization->id,
            'workspace_id' => $projectWorkspace->id,
            'project_id' => $project->id,
            'title' => 'Cross Organization Roadmap',
            'status' => Roadmap::STATUS_ACTIVE,
            'created_by' => $projectOwner->id,
        ]);
        $improvement = Improvement::create([
            'organization_id' => $projectOrganization->id,
            'workspace_id' => $projectWorkspace->id,
            'project_id' => $project->id,
            'roadmap_id' => $roadmap->id,
            'title' => 'Cross Organization Improvement',
            'visibility' => Improvement::VISIBILITY_INTERNAL,
            'proposed_by' => $projectOwner->id,
            'assigned_to' => $externalMember->id,
        ]);
        $task = Task::create([
            'organization_id' => $projectOrganization->id,
            'workspace_id' => $projectWorkspace->id,
            'project_id' => $project->id,
            'improvement_id' => $improvement->id,
            'title' => 'Cross Organization Task',
            'assigned_to' => $externalMember->id,
            'created_by' => $projectOwner->id,
        ]);

        $this->assertFalse($externalMember->organizations()->whereKey($projectOrganization->id)->exists());
        $this->assertTrue(Gate::forUser($externalMember)->allows('view', $project));
        $this->assertTrue(Gate::forUser($externalMember)->allows('update', $project));
        $this->assertTrue(Gate::forUser($externalMember)->allows('view', $roadmap));
        $this->assertTrue(Gate::forUser($externalMember)->allows('update', $roadmap));
        $this->assertTrue(Gate::forUser($externalMember)->allows('view', $improvement));
        $this->assertTrue(Gate::forUser($externalMember)->allows('update', $improvement));
        $this->assertTrue(Gate::forUser($externalMember)->allows('view', $task));
        $this->assertTrue(Gate::forUser($externalMember)->allows('update', $task));

        $activeSession = $this->companySession($memberOrganization) + [
            'current_workspace_id' => $memberWorkspace->id,
        ];
        $this->actingAs($externalMember)
            ->withSession($activeSession)
            ->get(route('projects.show', $project))
            ->assertOk();

        $this->lifecycle(
            $memberOrganizationOwner,
            $memberOrganization,
            $externalOrganizationMembership,
            'suspend',
            1,
            'cross-project-stop',
        );

        $this->assertFalse(Gate::forUser($externalMember)->allows('view', $project));
        $this->assertFalse(Gate::forUser($externalMember)->allows('update', $project));
        $this->assertFalse(Gate::forUser($externalMember)->allows('view', $roadmap));
        $this->assertFalse(Gate::forUser($externalMember)->allows('update', $roadmap));
        $this->assertFalse(Gate::forUser($externalMember)->allows('view', $improvement));
        $this->assertFalse(Gate::forUser($externalMember)->allows('update', $improvement));
        $this->assertFalse(Gate::forUser($externalMember)->allows('view', $task));
        $this->assertFalse(Gate::forUser($externalMember)->allows('update', $task));
        $this->actingAs($externalMember)
            ->withSession($activeSession)
            ->get(route('projects.show', $project))
            ->assertRedirect(route('companies.index'));
        $this->assertDatabaseHas('project_members', [
            'project_id' => $project->id,
            'user_id' => $externalMember->id,
            'workspace_id' => $memberWorkspace->id,
            'permission_level' => ProjectMember::PERMISSION_EDIT,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        $this->lifecycle(
            $memberOrganizationOwner,
            $memberOrganization,
            $externalOrganizationMembership->fresh(),
            'resume',
            2,
            'cross-project-resume',
        );
        $this->assertTrue(Gate::forUser($externalMember)->allows('view', $project));
        $this->actingAs($externalMember)
            ->withSession($activeSession)
            ->get(route('projects.show', $project))
            ->assertRedirect(route('companies.index'));
        $this->actingAs($externalMember)
            ->withSession($this->companySession($memberOrganization, 3) + [
                'current_workspace_id' => $memberWorkspace->id,
            ])
            ->get(route('projects.show', $project))
            ->assertOk();
    }

    public function test_relation_management_remains_available_but_new_grants_are_blocked_while_suspended(): void
    {
        $organization = $this->organization('relations');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $workspace = $this->workspace($organization, $owner, 'relations');
        $workspace->users()->attach($target->id, ['role' => 'member', 'joined_at' => now()]);
        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Relations Group']);
        OrganizationGroupMembership::create([
            'organization_group_id' => $group->id,
            'organization_user_id' => $membership->id,
            'added_by' => $owner->id,
        ]);
        $systemAdmin = User::factory()->create(['is_system_admin' => true]);

        $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'relations-stop');
        $this->actingAs($systemAdmin)
            ->withSession(['access_mode' => 'system_admin'])
            ->put(route('system-admin.members.workspaces.update', [$target, $workspace]), [
                'workspace_role' => 'admin',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => $target->id,
            'role' => 'admin',
        ]);

        $extraWorkspace = $this->workspace($organization, $owner, 'relations-extra');
        $this->actingAs($systemAdmin)
            ->withSession(['access_mode' => 'system_admin'])
            ->post(route('system-admin.members.workspaces.store', $target), [
                'workspace_id' => $extraWorkspace->id,
                'workspace_role' => 'member',
            ])
            ->assertSessionHasErrors('workspace_id');
        $this->artisan('ai:key:create', [
            'workspace' => (string) $workspace->id,
            'user' => $target->email,
        ])->assertFailed();

        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->delete(route('organization-management.groups.members.destroy', [$group, $membership]))
            ->assertRedirect();
        $this->actingAs($systemAdmin)
            ->withSession(['access_mode' => 'system_admin'])
            ->delete(route('system-admin.members.workspaces.destroy', [$target, $workspace]))
            ->assertRedirect();
        $this->assertDatabaseMissing('organization_group_memberships', ['organization_user_id' => $membership->id]);
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $target->id]);
        $this->assertDatabaseHas('organization_users', [
            'id' => $membership->id,
            'membership_status' => OrganizationUser::STATUS_SUSPENDED,
        ]);

        $this->lifecycle($owner, $organization, $membership->fresh(), 'resume', 2, 'relations-resume');
        $this->assertDatabaseMissing('organization_group_memberships', ['organization_user_id' => $membership->id]);
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $workspace->id, 'user_id' => $target->id]);
        $this->assertDatabaseMissing('workspace_members', ['workspace_id' => $extraWorkspace->id, 'user_id' => $target->id]);
    }

    public function test_minimal_ui_requires_reason_records_jst_history_and_exposes_no_member_bypass(): void
    {
        $organization = $this->organization('ui');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);

        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->get(route('organization-management.index'))
            ->assertOk()
            ->assertSee('MEMBERSHIP LIFECYCLE')
            ->assertSee('一時停止')
            ->assertSee('退職・所属終了')
            ->assertSee('一時停止は管理者が再開できます。所属終了はScope 5では再開できません。')
            ->assertSee('停止・退職にしてもRole、Position、Group、Workspace、Project、過去履歴は削除されません。');

        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->from(route('organization-management.index'))
            ->patch(route('organization-management.memberships.lifecycle', $membership), [])
            ->assertSessionHasErrors(['command', 'reason', 'expected_version', 'request_id']);

        $reason = '長期休職のため一時停止';
        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->patch(route('organization-management.memberships.lifecycle', $membership), [
                'command' => 'suspend',
                'reason' => $reason,
                'expected_version' => 1,
                'request_id' => 'ui-suspend',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $membership->refresh();
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $membership->membership_status);
        $this->assertSame($owner->id, $membership->status_changed_by_user_id);
        $this->assertSame($reason, $membership->status_change_reason);
        $this->assertNotNull($membership->status_changed_at);
        $this->assertSame('Asia/Tokyo', config('app.timezone'));
        $event = OrganizationAuditEvent::query()
            ->where('event', 'organization.membership.suspend')
            ->where('outcome', OrganizationAuditEvent::OUTCOME_SUCCESS)
            ->firstOrFail();
        $this->assertSame($reason, $event->metadata['reason']);
        $this->assertSame(2, $event->metadata['access_epoch']);

        $this->actingAs($target)
            ->get(route('account.profile'))
            ->assertOk()
            ->assertSee('一時停止中');
        $this->actingAs($target)
            ->withSession($this->companySession($organization, 2))
            ->get(route('organization-management.index'))
            ->assertRedirect(route('companies.index'));

        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->get(route('organization-management.index'))
            ->assertOk()
            ->assertSee('必要な場合は現在の権限で再発行・新規招待してください。');

        $other = $this->organization('ui-other');
        [, $otherMembership] = $this->member($other, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->patch(route('organization-management.memberships.lifecycle', $otherMembership), [
                'command' => 'suspend',
                'reason' => 'cross organization attempt',
                'expected_version' => 1,
                'request_id' => 'ui-cross-org',
            ])
            ->assertNotFound();
    }

    public function test_global_account_reactivation_never_reactivates_organization_membership(): void
    {
        $organization = $this->organization('global-state');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $systemAdmin = User::factory()->create(['is_system_admin' => true]);

        $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'global-stop');
        $target->update(['is_active' => false]);
        try {
            $this->lifecycle($owner, $organization, $membership->fresh(), 'resume', 2, 'global-resume-denied');
            $this->fail('A globally disabled Account must not resume.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('membership', $exception->errors());
        }
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $membership->fresh()->membership_status);

        $this->actingAs($systemAdmin)
            ->withSession(['access_mode' => 'system_admin'])
            ->put(route('system-admin.members.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'is_system_admin' => '0',
                'is_active' => '1',
            ])
            ->assertRedirect();
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $membership->fresh()->membership_status);

        $this->lifecycle($owner, $organization, $membership->fresh(), 'resume', 2, 'global-resume-approved');
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $membership->fresh()->membership_status);
    }

    public function test_profile_avatar_is_retained_private_and_uses_fallback_outside_existing_identity_access(): void
    {
        Storage::fake('local');
        $organization = $this->organization('avatar');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $path = 'avatars/'.$target->id.'/scope-five.webp';
        Storage::disk('local')->put($path, 'scope-five-avatar');
        $target->forceFill([
            'avatar_path' => $path,
            'avatar_mime' => 'image/webp',
            'avatar_width' => 40,
            'avatar_height' => 40,
            'avatar_updated_at' => now(),
        ])->save();

        $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'avatar-stop');
        $this->assertSame($path, $target->fresh()->avatar_path);
        Storage::disk('local')->assertExists($path);

        $this->actingAs($target)
            ->get(route('users.avatar', $target))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
        $this->actingAs($owner)
            ->get(route('users.avatar', $target))
            ->assertNotFound();
        $this->actingAs($owner)
            ->withSession($this->companySession($organization))
            ->get(route('organization-management.index'))
            ->assertOk()
            ->assertDontSee(route('users.avatar', $target), false)
            ->assertSee(mb_strtoupper(mb_substr(trim($target->name), 0, 1)));
    }

    public function test_user_with_no_active_organization_can_login_manage_account_and_logout_without_loop(): void
    {
        $organization = $this->organization('orgless');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        [$target, $membership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $this->lifecycle($owner, $organization, $membership, 'suspend', 1, 'orgless-stop');

        $this->post(route('login'), [
            'email' => $target->email,
            'password' => 'password',
        ])->assertRedirect(route('companies.index'));
        $this->get(route('companies.index'))
            ->assertOk()
            ->assertSee('Accountと所属履歴を確認する');
        $this->get(route('account.profile'))
            ->assertOk()
            ->assertSee('一時停止中');
        $this->post(route('logout'))
            ->assertRedirect(route('welcome'));
        $this->assertGuest();
    }

    private function organization(string $label): Organization
    {
        return Organization::create([
            'name' => 'Organization '.$label,
            'slug' => $label.'-'.strtolower((string) Str::ulid()),
        ]);
    }

    private function member(
        Organization $organization,
        string $role,
        ?User $user = null,
        string $status = OrganizationUser::STATUS_ACTIVE,
    ): array {
        $user ??= User::factory()->create(['email_verified_at' => now()]);
        $membership = OrganizationUser::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role === OrganizationUser::ORGANIZATION_ROLE_OWNER ? 'owner' : 'member',
            'organization_role' => $role,
            'membership_status' => $status,
            'company_role' => OrganizationUser::COMPANY_ROLE_MEMBER,
            'permissions' => [],
            'joined_at' => now(),
        ]);

        return [$user, $membership];
    }

    private function lifecycle(
        User $actor,
        Organization $organization,
        OrganizationUser $target,
        string $command,
        int $version,
        string $requestId,
        string $reason = 'Scope 5 test reason',
    ): OrganizationMembershipLifecycleOperation {
        return app(OrganizationMembershipLifecycle::class)->execute(
            $actor,
            $organization,
            $target,
            $command,
            $reason,
            $version,
            $requestId,
        );
    }

    private function workspace(Organization $organization, User $owner, string $label): Workspace
    {
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $owner->id,
            'name' => 'Workspace '.$label,
            'slug' => 'workspace-'.$label.'-'.strtolower((string) Str::ulid()),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $workspace->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        return $workspace;
    }

    private function aiKey(Workspace $workspace, User $user, string $label): AiAccessKey
    {
        WorkspaceAiSetting::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'enabled' => true,
                'provider' => 'test',
                'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
            ],
        );

        return AiAccessKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Key '.$label,
            'token_hash' => hash('sha256', 'token-'.$label),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ],
            'expires_at' => now()->addHour(),
        ]);
    }

    private function invitation(
        Organization $organization,
        User $sponsor,
        string $email,
        ?User $claimedUser = null,
        ?OrganizationUser $membership = null,
    ): OrganizationInvitation {
        return OrganizationInvitation::create([
            'organization_id' => $organization->id,
            'created_by_user_id' => $sponsor->id,
            'sponsor_user_id' => $sponsor->id,
            'normalized_email' => strtolower($email),
            'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'status' => OrganizationInvitation::STATUS_PENDING,
            'pending_email_key' => strtolower($email),
            'token_hash' => hash('sha256', 'scope-five-invitation-token'),
            'token_generation' => 1,
            'expires_at' => now()->addDay(),
            'claimed_user_id' => $claimedUser?->id,
            'organization_user_id' => $membership?->id,
            'delivery_status' => OrganizationInvitation::DELIVERY_QUEUED,
            'delivery_requested_at' => now(),
        ]);
    }

    private function companySession(Organization $organization, int $epoch = 1): array
    {
        return [
            'access_mode' => 'workspace',
            'current_company_id' => $organization->id,
            'current_company_access_epoch' => $epoch,
            'credential_generation' => 1,
        ];
    }
}
