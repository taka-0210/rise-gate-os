<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationUser;
use App\Models\ProductAccountEligibility;
use App\Models\ProductOrganizationCompatibility;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\AccountSeparation\AccountSeparationCutover;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountSeparationCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_cutover_is_atomic_idempotent_and_finalizes_only_from_suspended(): void
    {
        [$service, $old, $organization, $workspace, $new, , $inventoryHash] = $this->scenario();

        $prepared = $service->prepareStandardWorkspace($old, 'AS-G04-test-finalize');
        $this->assertSame(['before' => null, 'after' => 5, 'created_workspace' => false], $prepared);
        $result = $service->cutover('AS-G04-test-finalize', $new->id, $inventoryHash);

        $this->assertFalse($result['already_applied']);
        $this->assertSame($new->id, $workspace->fresh()->owner_user_id);
        $this->assertDatabaseHas('organization_users', [
            'id' => 5,
            'membership_status' => OrganizationUser::STATUS_SUSPENDED,
        ]);
        $this->assertDatabaseHas('organization_users', [
            'organization_id' => 4,
            'user_id' => $new->id,
            'role' => OrganizationUser::ROLE_OWNER,
            'organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'company_role' => OrganizationUser::COMPANY_ROLE_OWNER,
        ]);
        $this->assertDatabaseHas('product_account_eligibilities', [
            'id' => 1,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => 1,
        ]);
        $this->assertDatabaseHas('account_security_events', [
            'user_id' => $new->id,
            'event' => 'account.separation.cutover',
            'outcome' => 'success',
        ]);

        $retry = $service->cutover('AS-G04-test-finalize', $new->id, $inventoryHash);
        $this->assertTrue($retry['already_applied']);
        $this->assertSame(1, DB::table('organization_membership_lifecycle_operations')
            ->where('command', 'suspend')->count());
        $this->assertSame(1, DB::table('account_security_events')
            ->where('event', 'account.separation.cutover')->count());

        $finalized = $service->finalize('AS-G04-test-finalize', $new->id);
        $this->assertSame(OrganizationUser::STATUS_LEFT, $finalized['result_status']);
        $this->assertSame(OrganizationUser::STATUS_LEFT, OrganizationUser::query()->findOrFail(5)->membership_status);
        $this->assertSame(1, OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('membership_status', OrganizationUser::STATUS_ACTIVE)
            ->where('organization_role', OrganizationUser::ORGANIZATION_ROLE_OWNER)
            ->count());
    }

    public function test_suspended_cutover_can_be_compensated_without_rewinding_new_identity(): void
    {
        [$service, $old, , $workspace, $new, $invitation, $inventoryHash] = $this->scenario();
        $service->prepareStandardWorkspace($old, 'AS-G04-test-compensate');
        $service->cutover('AS-G04-test-compensate', $new->id, $inventoryHash);

        $result = $service->compensate('AS-G04-test-compensate', $new->id, $inventoryHash);

        $this->assertSame(OrganizationUser::STATUS_ACTIVE, $result['result_status']);
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, OrganizationUser::query()->findOrFail(5)->membership_status);
        $this->assertSame(1, $workspace->fresh()->owner_user_id);
        $this->assertDatabaseHas('product_account_eligibilities', [
            'id' => 1,
            'mode' => ProductAccountEligibility::MODE_LEGACY_MULTI,
            'product_organization_id' => null,
        ]);
        $this->assertNotNull($new->fresh()->email_verified_at);
        $this->assertSame(OrganizationInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertDatabaseHas('account_security_events', [
            'event' => 'account.separation.compensated',
            'outcome' => 'success',
        ]);

        try {
            $service->cutover('AS-G04-test-compensate', $new->id, $inventoryHash);
            $this->fail('A compensated case must not be reusable for cutover.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('compensated case cannot be reused', $exception->getMessage());
        }
        $this->assertSame(OrganizationUser::STATUS_ACTIVE, OrganizationUser::query()->findOrFail(5)->membership_status);
        $this->assertSame(ProductAccountEligibility::MODE_LEGACY_MULTI, ProductAccountEligibility::query()->findOrFail(1)->mode);
        $this->assertSame(1, $workspace->fresh()->owner_user_id);
    }

    private function scenario(): array
    {
        $old = User::factory()->create([
            'name' => 'Existing Takami',
            'is_active' => true,
            'is_system_admin' => true,
        ]);
        $fillerUsers = User::factory()->count(3)->create();
        $organizations = collect(range(1, 4))->map(fn (int $id): Organization => Organization::query()->create([
            'name' => 'Organization '.$id,
            'slug' => 'organization-'.$id,
        ]));
        $keep = $organizations->firstWhere('id', 1);
        $target = $organizations->firstWhere('id', 4);
        $keepMembership = $this->membership($keep, $old, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->membership($organizations->firstWhere('id', 2), $fillerUsers[0], OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $this->membership($organizations->firstWhere('id', 3), $fillerUsers[1], OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $this->membership($keep, $fillerUsers[2], OrganizationUser::ORGANIZATION_ROLE_MEMBER);
        $targetMembership = $this->membership($target, $old, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $this->assertSame(1, $keepMembership->id);
        $this->assertSame(5, $targetMembership->id);

        for ($id = 1; $id <= 4; $id++) {
            Workspace::query()->create([
                'organization_id' => $keep->id,
                'owner_user_id' => $old->id,
                'name' => 'Filler '.$id,
                'slug' => 'filler-'.$id,
                'status' => Workspace::STATUS_ACTIVE,
                'type' => Workspace::TYPE_SHARED,
            ]);
        }
        $workspace = Workspace::query()->create([
            'organization_id' => $target->id,
            'owner_user_id' => $old->id,
            'name' => '経営WS',
            'slug' => 'management',
            'status' => Workspace::STATUS_ACTIVE,
            'type' => Workspace::TYPE_SHARED,
        ]);
        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $old->id,
            'role' => WorkspaceMember::ROLE_OWNER,
            'joined_at' => now(),
        ]);
        $eligibility = ProductAccountEligibility::query()->create([
            'user_id' => $old->id,
            'mode' => ProductAccountEligibility::MODE_LEGACY_MULTI,
            'product_organization_id' => null,
            'classification_version' => 'pux-a-v1',
            'classified_at' => now(),
            'evidence_ref' => 'test:legacy-multi',
        ]);
        foreach ([$keepMembership, $targetMembership] as $membership) {
            ProductOrganizationCompatibility::query()->create([
                'product_account_eligibility_id' => $eligibility->id,
                'organization_user_id' => $membership->id,
                'cutoff_at' => now(),
                'evidence_ref' => 'test:compatibility',
            ]);
        }

        $service = app(AccountSeparationCutover::class);
        $inventoryHash = $service->inventoryHash($service->inventory());

        $new = User::factory()->create([
            'name' => AccountSeparationCutover::NEW_NAME,
            'email' => AccountSeparationCutover::NEW_EMAIL,
            'email_verified_at' => now(),
            'is_active' => true,
            'is_system_admin' => false,
        ]);
        $newMembership = $this->membership($target, $new, OrganizationUser::ORGANIZATION_ROLE_OWNER);
        $newMembership->forceFill([
            'role' => OrganizationUser::ROLE_MEMBER,
            'company_role' => OrganizationUser::COMPANY_ROLE_MEMBER,
        ])->save();
        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $new->id,
            'role' => WorkspaceMember::ROLE_MEMBER,
            'joined_at' => now(),
        ]);
        ProductAccountEligibility::query()->create([
            'user_id' => $new->id,
            'mode' => ProductAccountEligibility::MODE_SINGLE,
            'product_organization_id' => $target->id,
            'classification_version' => 'pux-a-v1',
            'classified_at' => now(),
            'evidence_ref' => 'admission:staff_invitation_accept',
        ]);
        $invitation = OrganizationInvitation::query()->create([
            'organization_id' => $target->id,
            'created_by_user_id' => $old->id,
            'sponsor_user_id' => $old->id,
            'normalized_email' => AccountSeparationCutover::NEW_EMAIL,
            'intended_organization_role' => OrganizationUser::ORGANIZATION_ROLE_OWNER,
            'status' => OrganizationInvitation::STATUS_ACCEPTED,
            'pending_email_key' => null,
            'token_hash' => hash('sha256', 'test-token'),
            'token_generation' => 1,
            'expires_at' => now()->addDay(),
            'organization_user_id' => $newMembership->id,
            'claimed_user_id' => $new->id,
            'accepted_by_user_id' => $new->id,
            'accepted_at' => now(),
        ]);

        return [$service, $old, $target, $workspace, $new, $invitation, $inventoryHash];
    }

    private function membership(Organization $organization, User $user, string $organizationRole): OrganizationUser
    {
        return OrganizationUser::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'role' => $organizationRole === OrganizationUser::ORGANIZATION_ROLE_OWNER
                ? OrganizationUser::ROLE_OWNER
                : OrganizationUser::ROLE_MEMBER,
            'organization_role' => $organizationRole,
            'membership_status' => OrganizationUser::STATUS_ACTIVE,
            'company_role' => $organizationRole === OrganizationUser::ORGANIZATION_ROLE_OWNER
                ? OrganizationUser::COMPANY_ROLE_OWNER
                : OrganizationUser::COMPANY_ROLE_MEMBER,
            'permissions' => [],
            'joined_at' => now(),
        ]);
    }
}
