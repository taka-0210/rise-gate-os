<?php

namespace Tests\Feature;

use App\Models\CompanyFinancialPeriod;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyFinanceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_owner_can_view_finance_and_manage_company_members(): void
    {
        [$owner, $organization, $workspace] = $this->companyUser('owner');
        CompanyFinancialPeriod::create([
            'organization_id' => $organization->id,
            'period_number' => 21,
            'fiscal_year' => 2024,
            'status' => CompanyFinancialPeriod::STATUS_ACTUAL,
            'net_sales' => 123456789,
        ]);

        $session = ['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id];

        $this->actingAs($owner)->withSession($session)
            ->get(route('company-finance.index'))
            ->assertOk()
            ->assertSee('123,456,789');

        $this->actingAs($owner)->withSession($session)
            ->get(route('company-members.index'))
            ->assertOk()
            ->assertSee('会社ユーザー・権限');
    }

    public function test_company_member_cannot_view_pl_without_explicit_permission(): void
    {
        [$member, , $workspace] = $this->companyUser('member');

        $this->actingAs($member)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->get(route('company-finance.index'))
            ->assertForbidden();
    }

    public function test_company_member_can_view_pl_with_explicit_permission(): void
    {
        [$member, $organization, $workspace] = $this->companyUser('member');
        OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->update(['permissions' => json_encode([OrganizationUser::PERMISSION_FINANCE_VIEW_PL])]);

        $this->actingAs($member)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->get(route('company-finance.index'))
            ->assertOk();
    }

    public function test_owner_can_assign_company_role_and_finance_permissions(): void
    {
        [$owner, $organization, $workspace] = $this->companyUser('owner');
        $member = User::factory()->create();
        $organization->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $this->actingAs($owner)
            ->withSession(['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id])
            ->put(route('company-members.update', $member), [
                'company_role' => OrganizationUser::COMPANY_ROLE_ACCOUNTING,
                'permissions' => [
                    OrganizationUser::PERMISSION_FINANCE_VIEW_PL,
                    OrganizationUser::PERMISSION_FINANCE_IMPORT_PL,
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('organization_users', [
            'organization_id' => $organization->id,
            'user_id' => $member->id,
            'company_role' => OrganizationUser::COMPANY_ROLE_ACCOUNTING,
        ]);

        $membership = OrganizationUser::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->firstOrFail();

        $this->assertContains(OrganizationUser::PERMISSION_FINANCE_VIEW_PL, $membership->permissions);
    }

    private function companyUser(string $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => 'Test Company',
            'slug' => 'test-company-'.uniqid(),
        ]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $role === 'owner' ? $user->id : null,
            'name' => 'Main Workspace',
            'slug' => 'main-'.uniqid(),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $organization->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => $role, 'joined_at' => now()]);

        return [$user, $organization, $workspace];
    }
}
