<?php

namespace Tests\Feature;

use App\Models\CompanyFinancialPeriod;
use App\Models\CompanyLoan;
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
            ->assertSee('id="repayment-modal-2024"', false)
            ->assertDontSee('<details', false)
            ->assertSee('3.00倍');
    }

    public function test_november_closing_year_uses_december_through_next_november(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00 Asia/Tokyo');
        [$user, $organization, $session] = $this->companyOwner(11);

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
            ->assertSee('今期')
            ->assertDontSee('1,700,000円')
            ->assertSee('1,200,000円')
            ->assertSee('500,000円')
            ->assertSee('借換えによる一括・完済返済')
            ->assertSee('返済方法：借換えで返済')
            ->assertSee('DSCR：算入しない')
            ->assertSee('extra-repayment-form-2025-', false)
            ->assertSee('No.22 年度境界銀行')
            ->assertSee('No.23 一括返済銀行')
            ->assertSee('2027年度')
            ->assertDontSee('2028年度');

        $completedLoan = CompanyLoan::where('management_number', '23')->firstOrFail();
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
