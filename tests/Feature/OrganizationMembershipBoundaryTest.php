<?php

namespace Tests\Feature;

use App\Models\AiAccessKey;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrganizationMembershipBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_membership_can_select_or_continue_in_an_organization(): void
    {
        [$user, $organization, $membership] = $this->companyFixture();

        $this->asCompany($user, $organization)
            ->get(route('company.home'))
            ->assertOk();

        foreach ([
            OrganizationUser::STATUS_INVITED,
            OrganizationUser::STATUS_SUSPENDED,
            OrganizationUser::STATUS_LEFT,
        ] as $status) {
            $membership->update(['membership_status' => $status]);

            $this->asCompany($user, $organization)
                ->get(route('company.home'))
                ->assertRedirect(route('companies.index'))
                ->assertSessionMissing('current_company_id')
                ->assertSessionMissing('current_workspace_id');

            $this->actingAs($user)
                ->withSession(['access_mode' => 'workspace'])
                ->post(route('companies.switch', $organization))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('account.profile'))
                ->assertOk()
                ->assertSee($organization->name)
                ->assertSee(OrganizationUser::membershipStatuses()[$status]);
        }

        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_suspended_organization_does_not_change_other_organization_or_workspace_relations(): void
    {
        $user = User::factory()->create();
        [$firstOrganization, $firstMembership, $firstWorkspace] = $this->organizationForUser(
            $user,
            'inactive-org',
            OrganizationUser::STATUS_SUSPENDED,
        );
        [$secondOrganization, $secondMembership, $secondWorkspace] = $this->organizationForUser(
            $user,
            'active-org',
            OrganizationUser::STATUS_ACTIVE,
        );

        $this->actingAs($user)
            ->withSession(['access_mode' => 'workspace'])
            ->get(route('companies.index'))
            ->assertRedirect(route('company.home'))
            ->assertSessionHas('current_company_id', $secondOrganization->id);

        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(OrganizationUser::STATUS_SUSPENDED, $firstMembership->fresh()->membership_status);
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $secondMembership->fresh()->membership_status);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $firstWorkspace->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $secondWorkspace->id, 'user_id' => $user->id]);
        $this->assertFalse($user->canAccessWorkspace($firstWorkspace->id));
        $this->assertTrue($user->canAccessWorkspace($secondWorkspace->id));
    }

    public function test_ai_key_rechecks_membership_status_and_other_organization_key_remains_usable(): void
    {
        $user = User::factory()->create();
        [$firstOrganization, $firstMembership, $firstWorkspace] = $this->organizationForUser(
            $user,
            'ai-first',
            OrganizationUser::STATUS_ACTIVE,
        );
        [$secondOrganization, , $secondWorkspace] = $this->organizationForUser(
            $user,
            'ai-second',
            OrganizationUser::STATUS_ACTIVE,
        );
        $firstToken = $this->aiKey($firstWorkspace, $user, 'first');
        $secondToken = $this->aiKey($secondWorkspace, $user, 'second');

        $this->withToken($firstToken)->getJson(route('api.ai.projects.index'))->assertOk();
        $key = AiAccessKey::query()->where('token_hash', hash('sha256', $firstToken))->firstOrFail();
        $key->forceFill(['last_used_at' => null])->save();

        foreach ([
            OrganizationUser::STATUS_INVITED,
            OrganizationUser::STATUS_SUSPENDED,
            OrganizationUser::STATUS_LEFT,
        ] as $status) {
            $firstMembership->update(['membership_status' => $status]);
            $key->forceFill(['last_used_at' => null])->save();
            $this->withToken($firstToken)
                ->getJson(route('api.ai.projects.index'))
                ->assertForbidden()
                ->assertJsonFragment(['message' => '現在の所属ではこのAI接続を利用できません。']);
            $this->assertNull($key->fresh()->last_used_at);
        }

        $this->withToken($secondToken)->getJson(route('api.ai.projects.index'))->assertOk();
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, OrganizationUser::query()
            ->where('organization_id', $secondOrganization->id)
            ->where('user_id', $user->id)
            ->value('membership_status'));
        $this->assertDatabaseHas('workspace_members', ['workspace_id' => $firstWorkspace->id, 'user_id' => $user->id]);
    }

    public function test_old_session_and_profile_payload_cannot_bypass_fresh_role_or_status(): void
    {
        [$owner, $organization, $membership] = $this->companyFixture();

        $this->asCompany($owner, $organization)
            ->get(route('organization-management.index'))
            ->assertOk();

        $membership->update(['organization_role' => OrganizationUser::ORGANIZATION_ROLE_MEMBER]);
        $this->get(route('organization-management.index'))->assertForbidden();

        $this->patch(route('account.profile.update'), [
            'name' => 'Safe Name',
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'position' => 'Injected Position',
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
        ])->assertRedirect();
        $membership->refresh();
        $this->assertSame(OrganizationUser::ORGANIZATION_ROLE_MEMBER, $membership->organization_role);
        $this->assertNull($membership->position);

        $membership->update(['membership_status' => OrganizationUser::STATUS_LEFT]);
        $this->withSession($this->companySession($organization))
            ->get(route('company.home'))
            ->assertRedirect(route('companies.index'));
    }

    private function companyFixture(): array
    {
        $user = User::factory()->create();
        [$organization, $membership] = $this->organizationForUser(
            $user,
            'company-'.Str::random(6),
            OrganizationUser::STATUS_ACTIVE,
            OrganizationUser::ORGANIZATION_ROLE_OWNER,
        );

        return [$user, $organization, $membership];
    }

    private function organizationForUser(
        User $user,
        string $slug,
        string $status,
        string $role = OrganizationUser::ORGANIZATION_ROLE_MEMBER,
    ): array {
        $organization = Organization::create([
            'name' => 'Organization '.$slug,
            'slug' => $slug.'-'.strtolower((string) Str::ulid()),
        ]);
        $membership = OrganizationUser::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $role === OrganizationUser::ORGANIZATION_ROLE_OWNER ? 'owner' : 'member',
            'organization_role' => $role,
            'membership_status' => $status,
            'joined_at' => now(),
        ]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Workspace '.$slug,
            'slug' => 'workspace-'.$slug.'-'.strtolower((string) Str::ulid()),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $user->workspaces()->attach($workspace->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$organization, $membership, $workspace];
    }

    private function aiKey(Workspace $workspace, User $user, string $suffix): string
    {
        WorkspaceAiSetting::create([
            'workspace_id' => $workspace->id,
            'enabled' => true,
            'provider' => 'test',
            'allowed_data_categories' => WorkspaceAiSetting::DEFAULT_DATA_CATEGORIES,
        ]);
        $token = 'scope3-'.$suffix.'-'.Str::random(48);
        AiAccessKey::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Scope 3 '.$suffix,
            'token_hash' => hash('sha256', $token),
            'scopes' => [AiAccessKey::SCOPE_PROJECTS_READ],
            'expires_at' => now()->addHour(),
        ]);

        return $token;
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
