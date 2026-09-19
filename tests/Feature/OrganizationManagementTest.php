<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationAuditEvent;
use App\Models\OrganizationGroup;
use App\Models\OrganizationGroupMembership;
use App\Models\OrganizationUser;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiProposalAuthorization;
use App\Services\Company\CompanyAccess;
use App\Services\Organization\OrganizationAdministration;
use App\Services\Organization\OrganizationAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_admin_member_matrix_and_direct_role_posts_are_enforced(): void
    {
        $organization = $this->organization('matrix');
        [$owner, $ownerMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER, 'owner');
        [$admin, $adminMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN, 'member');
        [$member, $memberMembership] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_MEMBER, 'member');

        $this->asCompany($owner, $organization)->get(route('organization-management.index'))
            ->assertOk()
            ->assertSee('Organization設定')
            ->assertSee($admin->email);
        $this->asCompany($admin, $organization)->get(route('organization-management.index'))->assertOk();
        $this->asCompany($admin, $organization)
            ->post(route('organization-management.groups.store'), ['name' => 'Admin管理Group'])
            ->assertRedirect();
        $this->assertDatabaseHas('organization_groups', [
            'organization_id' => $organization->id,
            'name' => 'Admin管理Group',
        ]);
        $this->asCompany($member, $organization)->get(route('organization-management.index'))->assertForbidden();

        $this->asCompany($admin, $organization)
            ->put(route('organization-management.memberships.role', $memberMembership), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            ])
            ->assertForbidden();
        $this->assertDatabaseHas('organization_audit_events', [
            'organization_id' => $organization->id,
            'actor_user_id' => $admin->id,
            'subject_user_id' => $member->id,
            'event' => 'organization.role.updated',
            'outcome' => OrganizationAuditEvent::OUTCOME_REJECTED,
        ]);
        $this->assertSame(
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            $memberMembership->fresh()->organization_role,
        );

        $this->asCompany($admin, $organization)
            ->put(route('organization-management.memberships.role', $adminMembership), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            ])
            ->assertForbidden();
        $this->assertSame(
            OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            $adminMembership->fresh()->organization_role,
        );

        $this->asCompany($owner, $organization)
            ->put(route('organization-management.memberships.role', $memberMembership), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            ])
            ->assertRedirect();
        $this->assertSame(
            OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            $memberMembership->fresh()->organization_role,
        );
        $this->assertSame('member', $memberMembership->role);

        $otherOrganization = $this->organization('other');
        [, $otherMembership] = $this->member($otherOrganization, OrganizationUser::ORGANIZATION_ROLE_MEMBER, 'member');
        $this->asCompany($owner, $organization)
            ->put(route('organization-management.memberships.role', $otherMembership), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_ADMIN,
            ])
            ->assertNotFound();

        $memberMembership->update(['membership_status' => OrganizationUser::STATUS_SUSPENDED]);
        $this->asCompany($owner, $organization)
            ->from(route('organization-management.index'))
            ->put(route('organization-management.memberships.role', $memberMembership), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            ])
            ->assertRedirect(route('organization-management.index'))
            ->assertSessionHasErrors('membership');
    }

    public function test_last_active_owner_is_preserved_under_serialized_stale_intents(): void
    {
        $organization = $this->organization('owners');
        [$firstOwner, $firstMembership] = $this->member(
            $organization,
            OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'owner',
        );
        [, $memberMembership] = $this->member(
            $organization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'member',
        );

        $this->asCompany($firstOwner, $organization)
            ->put(route('organization-management.memberships.role', $firstMembership), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            ])
            ->assertSessionHasErrors('organization_role');
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_OWNER, $firstMembership->fresh()->organization_role);

        $memberMembership->update(['organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER]);
        $staleFirst = $firstMembership->fresh();
        $staleSecond = $memberMembership->fresh();

        $this->asCompany($firstOwner, $organization)
            ->put(route('organization-management.memberships.role', $staleFirst), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            ])
            ->assertRedirect();

        $this->actingAs($staleSecond->user)
            ->withSession($this->companySession($organization))
            ->put(route('organization-management.memberships.role', $staleSecond), [
                'organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            ])
            ->assertSessionHasErrors('organization_role');

        $this->assertSame(
            1,
            OrganizationUser::query()
                ->where('organization_id', $organization->id)
                ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
                ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
                ->count(),
        );
    }

    public function test_position_is_organization_specific_and_never_changes_resource_permissions(): void
    {
        $organization = $this->organization('position-a');
        [$admin] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_ADMIN, 'member');
        [$target, $targetMembership] = $this->member(
            $organization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'member',
            OrganizationUser::COMPANY_ROLE_ACCOUNTING,
            [OrganizationUser::PERMISSION_FINANCE_VIEW_PL],
        );
        $otherOrganization = $this->organization('position-b');
        [, $otherMembership] = $this->member(
            $otherOrganization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'member',
            user: $target,
        );
        [$workspace, $projectMembership] = $this->projectMembership(
            $organization,
            $target,
            ProjectMember::PERMISSION_VIEW,
        );

        $before = $targetMembership->only(['role', 'company_role', 'permissions']);
        $this->asCompany($admin, $organization)
            ->put(route('organization-management.memberships.position', $targetMembership), [
                'position' => '営業部長',
            ])
            ->assertRedirect();

        $targetMembership->refresh();
        $this->assertSame('営業部長', $targetMembership->position);
        $this->assertNull($otherMembership->fresh()->position);
        $this->assertSame($before, $targetMembership->only(['role', 'company_role', 'permissions']));
        $this->assertSame(ProjectMember::PERMISSION_VIEW, $projectMembership->fresh()->permission_level);
        $this->assertSame('member', $target->workspaces()->whereKey($workspace->id)->first()->pivot->role);
        $this->assertTrue(app(CompanyAccess::class)->allows(
            $target,
            $organization,
            OrganizationUser::PERMISSION_FINANCE_VIEW_PL,
        ));
    }

    public function test_group_crud_is_idempotent_cross_org_safe_and_grants_no_permission(): void
    {
        $organization = $this->organization('groups');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER, 'owner');
        [$target, $targetMembership] = $this->member(
            $organization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'member',
        );

        $this->asCompany($owner, $organization)
            ->post(route('organization-management.groups.store'), ['name' => '営業'])
            ->assertRedirect();
        $group = OrganizationGroup::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->asCompany($owner, $organization)
            ->post(route('organization-management.groups.store'), ['name' => '営業'])
            ->assertSessionHasErrors('name');

        $this->asCompany($owner, $organization)
            ->put(route('organization-management.groups.update', $group), ['name' => '営業本部'])
            ->assertRedirect();
        $this->assertSame('営業本部', $group->fresh()->name);

        foreach ([1, 2] as $attempt) {
            $this->asCompany($owner, $organization)
                ->post(route('organization-management.groups.members.store', $group), [
                    'organization_user_id' => $targetMembership->id,
                ])
                ->assertRedirect();
        }
        $this->assertSame(1, OrganizationGroupMembership::query()->count());

        $this->asCompany($owner, $organization)
            ->post(route('organization-management.groups.store'), ['name' => '改善'])
            ->assertRedirect();
        $secondGroup = OrganizationGroup::query()->where('name', '改善')->firstOrFail();
        $this->asCompany($owner, $organization)
            ->post(route('organization-management.groups.members.store', $secondGroup), [
                'organization_user_id' => $targetMembership->id,
            ])
            ->assertRedirect();
        $this->assertSame(2, $targetMembership->groups()->count());
        $this->assertSame([], $targetMembership->fresh()->permissions ?? []);
        $this->assertFalse($target->projectMemberships()->exists());
        $this->assertFalse($target->workspaces()->exists());

        $otherOrganization = $this->organization('group-other');
        [, $otherMembership] = $this->member($otherOrganization, OrganizationUser::ORGANIZATION_ROLE_MEMBER, 'member');
        $this->asCompany($owner, $organization)
            ->post(route('organization-management.groups.members.store', $group), [
                'organization_user_id' => $otherMembership->id,
            ])
            ->assertNotFound();

        $this->asCompany($owner, $organization)
            ->delete(route('organization-management.groups.archive', $group))
            ->assertSessionHasErrors('group');
        foreach ([1, 2] as $attempt) {
            $this->asCompany($owner, $organization)
                ->delete(route('organization-management.groups.members.destroy', [$group, $targetMembership]))
                ->assertRedirect();
        }
        $this->asCompany($owner, $organization)
            ->delete(route('organization-management.groups.archive', $group))
            ->assertRedirect();

        $this->assertNotNull($group->fresh()->archived_at);
        $this->assertDatabaseHas('organization_groups', ['id' => $group->id, 'name' => '営業本部']);
        $this->assertDatabaseHas('organization_audit_events', [
            'organization_id' => $organization->id,
            'event' => 'organization.group.archived',
            'outcome' => OrganizationAuditEvent::OUTCOME_SUCCESS,
        ]);
    }

    public function test_audit_is_atomic_sanitized_and_not_exposed_to_non_managers(): void
    {
        $organization = $this->organization('audit');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER, 'owner');
        [$target, $targetMembership] = $this->member(
            $organization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'member',
        );

        $this->asCompany($owner, $organization)
            ->put(route('organization-management.memberships.position', $targetMembership), [
                'position' => '経理責任者',
            ])
            ->assertRedirect();

        $event = OrganizationAuditEvent::query()->latest('id')->firstOrFail();
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame($target->id, $event->subject_user_id);
        $this->assertSame(['position' => null], $event->before_data);
        $this->assertSame(['position' => '経理責任者'], $event->after_data);
        $serialized = json_encode($event->toArray());
        $this->assertStringNotContainsString($target->email, $serialized);
        $this->assertStringNotContainsString('password', strtolower($serialized));
        $this->assertStringNotContainsString('token', strtolower($serialized));

        $sanitizedEvent = app(OrganizationAudit::class)->record(
            $organization,
            $owner,
            'organization.audit.sanitize_test',
            OrganizationAuditEvent::OUTCOME_SUCCESS,
            $target,
            before: ['position' => '経理責任者', 'password' => 'must-not-persist'],
            metadata: ['nested' => ['api_token' => 'must-not-persist', 'safe' => 'kept']],
        );
        $this->assertSame(['position' => '経理責任者'], $sanitizedEvent->before_data);
        $this->assertSame(['nested' => ['safe' => 'kept']], $sanitizedEvent->metadata);

        $mockAudit = \Mockery::mock(OrganizationAudit::class);
        $mockAudit->shouldReceive('record')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->app->instance(OrganizationAudit::class, $mockAudit);

        try {
            app(OrganizationAdministration::class)->updatePosition(
                $owner,
                $organization,
                $targetMembership->fresh(),
                '取締役',
            );
            $this->fail('Audit failure must abort the mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertSame('経理責任者', $targetMembership->fresh()->position);
        $this->assertSame(2, OrganizationAuditEvent::query()->count());

        $this->asCompany($target, $organization)
            ->get(route('organization-management.index'))
            ->assertForbidden()
            ->assertDontSee('経理責任者');
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($route) => str_contains($route->uri(), 'organization-audit')));
    }

    public function test_organization_role_and_group_do_not_grant_scope_one_apply(): void
    {
        $organization = $this->organization('scope-one');
        [$owner] = $this->member($organization, OrganizationUser::ORGANIZATION_ROLE_OWNER, 'owner');
        [$target, $targetMembership] = $this->member(
            $organization,
            OrganizationUser::ORGANIZATION_ROLE_MEMBER,
            'member',
        );
        [, $projectMembership, $project] = $this->projectMembership(
            $organization,
            $target,
            ProjectMember::PERMISSION_VIEW,
        );
        $group = OrganizationGroup::create(['organization_id' => $organization->id, 'name' => 'Project外Group']);

        $authorization = app(AiProposalAuthorization::class);
        $this->assertFalse($authorization->canReview($target, $project));

        app(OrganizationAdministration::class)->updateRole(
            $owner,
            $organization,
            $targetMembership,
            OrganizationUser::ORGANIZATION_ROLE_ADMIN,
        );
        app(OrganizationAdministration::class)->addGroupMember(
            $owner,
            $organization,
            $group,
            $targetMembership,
        );

        $this->assertFalse($authorization->canReview($target, $project));
        $this->assertSame(ProjectMember::PERMISSION_VIEW, $projectMembership->fresh()->permission_level);
    }

    private function organization(string $slug): Organization
    {
        return Organization::create([
            'name' => 'Organization '.$slug,
            'slug' => $slug.'-'.strtolower((string) Str::ulid()),
        ]);
    }

    private function member(
        Organization $organization,
        string $organizationRole,
        string $legacyRole,
        ?string $companyRole = null,
        array $permissions = [],
        ?User $user = null,
        string $status = OrganizationUser::STATUS_ACTIVE,
    ): array {
        $user ??= User::factory()->create();
        $membership = OrganizationUser::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $legacyRole,
            'organization_role' => $organizationRole,
            'membership_status' => $status,
            'company_role' => $companyRole,
            'permissions' => $permissions,
            'joined_at' => now(),
        ]);

        return [$user, $membership];
    }

    private function projectMembership(
        Organization $organization,
        User $user,
        string $permission,
    ): array {
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Workspace '.Str::random(8),
            'slug' => 'workspace-'.strtolower((string) Str::ulid()),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $user->workspaces()->attach($workspace->id, ['role' => 'member', 'joined_at' => now()]);
        $project = Project::create([
            'organization_id' => $organization->id,
            'owning_workspace_id' => $workspace->id,
            'billing_workspace_id' => $workspace->id,
            'owner_user_id' => $user->id,
            'name' => 'Project '.Str::random(8),
        ]);
        $membership = ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'project_role' => ProjectMember::ROLE_VIEWER,
            'permission_level' => $permission,
            'status' => ProjectMember::STATUS_ACTIVE,
        ]);

        return [$workspace, $membership, $project];
    }

    private function asCompany(User $user, Organization $organization): static
    {
        return $this->actingAs($user)->withSession($this->companySession($organization));
    }

    private function companySession(Organization $organization): array
    {
        return [
            'access_mode' => 'workspace',
            'current_company_id' => $organization->id,
            'credential_generation' => 1,
        ];
    }
}
