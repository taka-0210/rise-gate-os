<?php

namespace Tests\Feature;

use App\Models\CompanyFinancialPeriod;
use App\Models\CompanyAnnualPlan;
use App\Models\CompanyLoan;
use App\Models\CompanyRepaymentScenario;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyRepaymentCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_save_depreciation_and_view_annual_repayment_capacity(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00 Asia/Tokyo');
        [$user, $organization, $session] = $this->companyOwner();

        CompanyFinancialPeriod::create([
            'organization_id' => $organization->id,
            'period_number' => 21,
            'fiscal_year' => 2024,
            'status' => CompanyFinancialPeriod::STATUS_ACTUAL,
            'record_status' => CompanyFinancialPeriod::RECORD_CONFIRMED,
            'source_type' => CompanyFinancialPeriod::SOURCE_MANUAL,
            'net_income' => 3_000_000,
            'interest_expense' => 200_000,
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
            ->assertSee('2025年度')
            ->assertSee('未登録')
            ->assertSee('2028年度')
            ->assertDontSee('2029年度')
            ->assertSee('DSCR')
            ->assertSee('3,000,000円')
            ->assertSee('200,000円')
            ->assertSee('4,200,000円')
            ->assertSee('1,200,000円')
            ->assertSee('1,400,000円')
            ->assertSee('2,800,000円')
            ->assertSee('No.1 テスト銀行')
            ->assertSee('id="year-detail-2024"', false)
            ->assertSee('data-money-input', false)
            ->assertDontSee('<details', false)
            ->assertSee('安全')
            ->assertSee('今期の改善シミュレーション')
            ->assertSee('3.00倍');
    }

    public function test_november_closing_year_uses_december_through_next_november(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00 Asia/Tokyo');
        [$user, $organization, $session] = $this->companyOwner(11);

        $annualPlan = CompanyAnnualPlan::create([
            'organization_id' => $organization->id,
            'period_number' => 22,
            'fiscal_year' => 2025,
            'plan_net_sales' => 614_000_000,
            'plan_net_income' => 10_000_000,
            'plan_interest_expense' => 4_000_000,
        ]);
        CompanyLoan::create([
            'organization_id' => $organization->id,
            'financial_institution' => '年度境界銀行',
            'management_number' => '22',
            'executed_on' => '2025-12-01',
            'term_label' => '1年',
            'original_amount' => 1_200_000,
            'current_balance' => 0,
            'monthly_principal_payment' => 100_000,
            'balance_projection_mode' => CompanyLoan::PROJECTION_AMORTIZING,
            'maturity_on' => '2026-12-01',
            'balance_as_of' => '2026-11-30',
            'loan_status' => CompanyLoan::STATUS_ACTIVE,
            'record_status' => CompanyLoan::RECORD_CONFIRMED,
            'source_type' => CompanyLoan::SOURCE_MANUAL,
        ]);
        CompanyLoan::create([
            'organization_id' => $organization->id,
            'financial_institution' => '一括返済銀行',
            'management_number' => '23',
            'executed_on' => '2025-12-01',
            'term_label' => '一括',
            'original_amount' => 500_000,
            'current_balance' => 0,
            'monthly_principal_payment' => 0,
            'balance_projection_mode' => CompanyLoan::PROJECTION_HOLD,
            'completed_on' => '2026-03-31',
            'extra_repayment_funding' => CompanyLoan::EXTRA_REPAYMENT_REFINANCE,
            'balance_as_of' => '2026-03-31',
            'loan_status' => CompanyLoan::STATUS_COMPLETED,
            'record_status' => CompanyLoan::RECORD_CONFIRMED,
            'source_type' => CompanyLoan::SOURCE_MANUAL,
        ]);

        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.repayment-capacity.index'))
            ->assertOk()
            ->assertSee('2025年度')
            ->assertSee('年度計画')
            ->assertSee('03 今年度計画と進捗「年度計画」')
            ->assertSee('614000000')
            ->assertDontSee('1,700,000円')
            ->assertSee('1,200,000円')
            ->assertSee('500,000円')
            ->assertSee('借換えによる一括返済')
            ->assertSee('返済方法：借換えで返済')
            ->assertSee('DSCR：算入しない')
            ->assertSee('funding-form-2025-', false)
            ->assertSee('No.22 年度境界銀行')
            ->assertSee('No.23 一括返済銀行')
            ->assertSee('2027年度')
            ->assertDontSee('2028年度');

        $annualPlan->update([
            'forecast_net_sales' => 620_000_000,
            'forecast_net_income' => 12_000_000,
            'forecast_interest_expense' => 3_500_000,
        ]);
        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.repayment-capacity.index'))
            ->assertOk()
            ->assertSee('03 今年度計画と進捗「最新見込」')
            ->assertSee('620000000');

        $completedLoan = CompanyLoan::where('management_number', '23')->firstOrFail();
        $scenarioInput = [
            'fiscal_year' => 2025,
            'net_sales' => 50_000_000,
            'net_income' => 3_000_000,
            'depreciation_expense' => 1_000_000,
            'interest_expense' => 100_000,
            'execute_extra_repayments' => true,
            'extra_repayment_funding_overrides' => [
                (string) $completedLoan->id => CompanyLoan::EXTRA_REPAYMENT_REFINANCE,
            ],
            'new_loans' => [[
                'amount' => 0,
                'executed_on' => null,
                'term_months' => 60,
                'annual_interest_rate' => 0,
                'repayment_mode' => 'amortizing',
            ]],
        ];
        $this->actingAs($user)->withSession($session)
            ->postJson(route('company-finance.repayment-capacity.simulate'), $scenarioInput)
            ->assertOk()
            ->assertJsonPath('principal_repayment', 1_200_000)
            ->assertJsonPath('refinanced_principal_repayment', 500_000);

        $this->actingAs($user)->withSession($session)
            ->put(route('company-finance.repayment-capacity.extra-repayment-funding', $completedLoan), [
                'extra_repayment_funding' => CompanyLoan::EXTRA_REPAYMENT_SELF_FUNDED,
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '一括返済の資金区分を更新しました。');

        $this->assertSame(
            CompanyLoan::EXTRA_REPAYMENT_SELF_FUNDED,
            $completedLoan->fresh()->extra_repayment_funding,
        );
    }

    public function test_owner_can_simulate_and_save_current_year_decisions_without_changing_actuals(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00 Asia/Tokyo');
        [$user, $organization, $session] = $this->companyOwner();

        $input = [
            'fiscal_year' => 2026,
            'net_sales' => 100_000_000,
            'net_income' => 1_000_000,
            'depreciation_expense' => 500_000,
            'interest_expense' => 100_000,
            'execute_extra_repayments' => true,
            'extra_repayment_funding_overrides' => [],
            'new_loans' => [[
                'amount' => 12_000_000,
                'executed_on' => '2026-01-01',
                'term_months' => 12,
                'annual_interest_rate' => 12,
                'repayment_mode' => 'amortizing',
            ]],
        ];

        $response = $this->actingAs($user)->withSession($session)
            ->postJson(route('company-finance.repayment-capacity.simulate'), $input)
            ->assertOk()
            ->assertJsonPath('principal_repayment', 11_000_000)
            ->assertJsonPath('new_loan_interest_expense', 890_000)
            ->assertJsonPath('annual_debt_service', 11_990_000)
            ->assertJsonPath('net_income', 110_000)
            ->assertJsonPath('repayment_source', 1_600_000)
            ->assertJsonPath('shortfall', 10_390_000)
            ->assertJsonPath('assessment.label', '要改善');
        $this->assertLessThan(1, $response->json('coverage_ratio'));

        $this->actingAs($user)->withSession($session)
            ->putJson(route('company-finance.repayment-capacity.scenario.save'), $input)
            ->assertOk()
            ->assertJsonPath('message', '今期の経営判断シナリオを保存しました。');

        $scenario = CompanyRepaymentScenario::firstOrFail();
        $this->assertSame(2026, $scenario->fiscal_year);
        $this->assertSame(100_000_000, (int) $scenario->net_sales);
        $this->assertSame(12_000_000, (int) $scenario->new_loans[0]['amount']);
        $this->assertDatabaseCount('company_financial_periods', 0);
        $this->assertDatabaseCount('company_loans', 0);

        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.repayment-capacity.index'))
            ->assertOk()
            ->assertSee('今期計画')
            ->assertSee('11,000,000円')
            ->assertSee('要改善');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function companyOwner(int $closingMonth = 12): array
    {
        $user = User::factory()->create();
        $organization = Organization::create([
            'name' => '返済余力テスト株式会社',
            'slug' => 'capacity-'.uniqid(),
            'fiscal_year_end_month' => $closingMonth,
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
