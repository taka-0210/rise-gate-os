<?php

namespace Tests\Feature;

use App\Models\CompanyFinancialPeriod;
use App\Models\CompanyLoan;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyRepaymentCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_depreciation_and_view_annual_repayment_capacity(): void
    {
        [$user, $organization, $session] = $this->companyOwner();

        CompanyFinancialPeriod::create([
            'organization_id' => $organization->id,
            'period_number' => 21,
            'fiscal_year' => 2024,
            'status' => CompanyFinancialPeriod::STATUS_ACTUAL,
            'record_status' => CompanyFinancialPeriod::RECORD_CONFIRMED,
            'source_type' => CompanyFinancialPeriod::SOURCE_MANUAL,
            'net_income' => 3_000_000,
        ]);
        CompanyLoan::create([
            'organization_id' => $organization->id,
            'financial_institution' => 'テスト銀行',
            'management_number' => '1',
            'executed_on' => '2024-01-01',
            'term_label' => '1年',
            'original_amount' => 1_200_000,
            'current_balance' => 0,
            'monthly_principal_payment' => 100_000,
            'balance_projection_mode' => CompanyLoan::PROJECTION_AMORTIZING,
            'maturity_on' => '2025-01-01',
            'balance_as_of' => '2024-12-31',
            'loan_status' => CompanyLoan::STATUS_ACTIVE,
            'record_status' => CompanyLoan::RECORD_CONFIRMED,
            'source_type' => CompanyLoan::SOURCE_MANUAL,
        ]);

        $this->actingAs($user)->withSession($session)
            ->put(route('company-finance.repayment-capacity.update'), [
                'depreciation' => [2024 => 1_000_000],
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '年度別の減価償却費を保存しました。');

        $this->assertDatabaseHas('company_depreciation_periods', [
            'organization_id' => $organization->id,
            'fiscal_year' => 2024,
            'depreciation_expense' => 1_000_000,
            'updated_by' => $user->id,
        ]);

        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.repayment-capacity.index'))
            ->assertOk()
            ->assertSee('減価償却・返済余力')
            ->assertSee('簡易DSCR')
            ->assertSee('一般的なDSCRでは元利返済額を使う')
            ->assertSee('3,000,000円')
            ->assertSee('4,000,000円')
            ->assertSee('1,200,000円')
            ->assertSee('2,800,000円')
            ->assertSee('3.33倍');
    }

    private function companyOwner(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => '返済余力テスト株式会社',
            'slug' => 'capacity-'.uniqid(),
            'fiscal_year_end_month' => 12,
        ]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id,
            'owner_user_id' => $user->id,
            'name' => 'Main',
            'slug' => 'main-'.uniqid(),
            'status' => Workspace::STATUS_ACTIVE,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$user, $organization, [
            'access_mode' => 'workspace',
            'current_company_id' => $organization->id,
            'current_workspace_id' => $workspace->id,
        ]];
    }
}
