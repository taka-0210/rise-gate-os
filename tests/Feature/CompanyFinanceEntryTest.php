<?php

namespace Tests\Feature;

use App\Models\CompanyFinancialPeriod;
use App\Models\CompanyAnnualPlan;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyFinanceEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_preview_save_confirm_and_edit_annual_pl(): void
    {
        [$user, $organization, $session] = $this->companyOwner();
        $input = $this->input();

        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.preview'), $input)
            ->assertOk()->assertSee('40,000,000円')->assertSee('10,000,000円');

        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.store'), $input)
            ->assertRedirect();

        $period = CompanyFinancialPeriod::firstOrFail();
        $this->assertSame(40_000_000, $period->gross_profit);
        $this->assertSame(10_000_000, $period->operating_profit);
        $this->assertSame(500_000, $period->interest_expense);
        $this->assertSame(CompanyFinancialPeriod::RECORD_DRAFT, $period->record_status);
        $this->assertCount(1, $period->revisions);

        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.confirm', $period))
            ->assertRedirect();
        $this->assertSame(CompanyFinancialPeriod::RECORD_CONFIRMED, $period->fresh()->record_status);

        $input['net_sales'] = 110_000_000;
        $this->actingAs($user)->withSession($session)
            ->put(route('company-finance.pl.update', $period), $input)
            ->assertRedirect();
        $this->assertSame(CompanyFinancialPeriod::RECORD_DRAFT, $period->fresh()->record_status);
        $this->assertCount(3, $period->fresh()->revisions);
    }

    public function test_owner_can_update_closing_month_and_bulk_paste_pl(): void
    {
        [$user, $organization, $session] = $this->companyOwner();

        $this->actingAs($user)->withSession($session)
            ->put(route('company-finance.settings.update'), ['fiscal_year_end_month' => 11])
            ->assertRedirect();
        $this->assertSame(11, $organization->fresh()->fiscal_year_end_month);

        $text = "期\t年度\t売上高\t売上原価\t販管費\t営業外収益\t営業外費用\t特別利益\t特別損失\t法人税等\n".
            "20\t2023\t90000000\t50000000\t30000000\t0\t0\t0\t0\t3000000\n".
            "21\t2024\t100000000\t60000000\t30000000\t0\t0\t0\t0\t3000000";

        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.bulk.preview'), ['bulk_text' => $text])
            ->assertOk()->assertSee('2期分を確認');

        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.bulk.store'), ['bulk_text' => $text])
            ->assertRedirect(route('company-finance.pl.index'));

        $this->assertDatabaseCount('company_financial_periods', 2);
        $this->assertDatabaseHas('company_financial_periods', [
            'organization_id' => $organization->id, 'fiscal_year' => 2024,
            'source_type' => CompanyFinancialPeriod::SOURCE_BULK,
            'record_status' => CompanyFinancialPeriod::RECORD_DRAFT,
        ]);
        $periods = CompanyFinancialPeriod::orderBy('fiscal_year')->get();
        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.confirm-drafts'), [
                'scope' => 'selected', 'ids' => [$periods->first()->id],
            ])->assertRedirect();
        $this->assertSame(CompanyFinancialPeriod::RECORD_CONFIRMED, $periods->first()->fresh()->record_status);
        $this->assertSame(CompanyFinancialPeriod::RECORD_DRAFT, $periods->last()->fresh()->record_status);

        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.confirm-drafts'), ['scope' => 'all'])
            ->assertRedirect();
        $this->assertSame(CompanyFinancialPeriod::RECORD_CONFIRMED, $periods->last()->fresh()->record_status);
        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.pl.index'))
            ->assertOk()->assertDontSee('<input type="checkbox" data-check-all', false);

        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.pl.bulk'))
            ->assertOk()->assertSee('1</b><span>期', false)->assertSee('11</b><span>法人税等', false);
    }

    public function test_interest_expense_must_not_exceed_non_operating_expenses(): void
    {
        [$user, , $session] = $this->companyOwner();
        $input = $this->input();
        $input['non_operating_expenses'] = 585_644;
        $input['interest_expense'] = 5_792_440;

        $this->actingAs($user)->withSession($session)
            ->post(route('company-finance.pl.preview'), $input)
            ->assertSessionHasErrors([
                'interest_expense' => '支払利息は、営業外費用以下の金額を入力してください。支払利息は営業外費用の内数です。',
            ]);
    }

    public function test_owner_can_save_current_annual_plan_and_latest_forecast_separately_from_pl(): void
    {
        CarbonImmutable::setTestNow('2026-07-30 12:00:00 Asia/Tokyo');
        [$user, $organization, $session] = $this->companyOwner();
        $organization->update(['fiscal_year_end_month' => 11]);

        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.annual-plan.index'))
            ->assertOk()
            ->assertSee('2025年度')
            ->assertSee('2025.12〜2026.11');

        $this->actingAs($user)->withSession($session)
            ->put(route('company-finance.annual-plan.update'), [
                'period_number' => 22,
                'plan_net_sales' => 614_000_000,
                'plan_gross_profit' => 273_230_000,
                'plan_selling_general_admin_expenses' => 267_230_000,
                'plan_net_income' => 10_000_000,
                'plan_interest_expense' => 4_000_000,
                'plan_depreciation_expense' => 30_000_000,
                'forecast_net_income' => 12_000_000,
                'forecast_interest_expense' => 3_500_000,
                'forecast_depreciation_expense' => 31_000_000,
                'months' => collect(range(0, 11))->map(function (int $index) {
                    $month = CarbonImmutable::create(2025, 12, 1, 0, 0, 0, 'Asia/Tokyo')->addMonths($index);

                    return [
                        'month' => $month->format('Y-m-d'),
                        'plan_net_sales' => 50_000_000,
                        'actual_net_sales' => $index < 7 ? 45_000_000 : null,
                        'actual_cost_of_sales' => $index < 7 ? 25_000_000 : null,
                        'actual_selling_general_admin_expenses' => $index < 7 ? 20_000_000 : null,
                    ];
                })->all(),
            ])
            ->assertRedirect()
            ->assertSessionHas('status', '今年度の計画・月次進捗・最新見込を保存しました。');

        $plan = CompanyAnnualPlan::firstOrFail();
        $this->assertSame(2025, $plan->fiscal_year);
        $this->assertSame(614_000_000, (int) $plan->plan_net_sales);
        $this->assertSame(565_000_000, (int) $plan->forecast_net_sales);
        $this->assertSame(12_000_000, (int) $plan->forecast_net_income);
        $this->assertCount(12, $plan->months);
        $this->assertDatabaseCount('company_financial_periods', 0);

        $this->actingAs($user)->withSession($session)
            ->get(route('company-finance.annual-plan.index'))
            ->assertOk()
            ->assertSee('単月')
            ->assertSee('累計')
            ->assertSee('最新の着地見込み')
            ->assertSee('data-tax-mode="inclusive"', false)
            ->assertDontSee('厨房君');
    }

    public function test_22nd_plan_progress_is_seeded_from_the_source_workbook_as_tax_excluded_amounts(): void
    {
        [$user, $organization] = $this->companyOwner();
        $plan = CompanyAnnualPlan::create([
            'organization_id' => $organization->id,
            'fiscal_year' => 2025,
            'period_number' => 22,
            'plan_net_sales' => 614_000_000,
            'plan_gross_profit' => 273_230_000,
            'plan_selling_general_admin_expenses' => 267_230_000,
            'updated_by' => $user->id,
        ]);

        $migration = require database_path('migrations/2026_07_30_000007_seed_22nd_annual_plan_progress.php');
        $migration->up();

        $this->assertCount(12, $plan->fresh()->months);
        $this->assertDatabaseHas('company_annual_plan_months', [
            'company_annual_plan_id' => $plan->id,
            'month' => '2025-12-01',
            'plan_net_sales' => 51_166_666,
            'actual_net_sales' => 43_711_274,
            'actual_cost_of_sales' => 25_238_002,
            'actual_selling_general_admin_expenses' => 22_269_166,
        ]);
        $this->assertDatabaseHas('company_annual_plan_months', [
            'company_annual_plan_id' => $plan->id,
            'month' => '2026-11-01',
            'plan_net_sales' => 51_166_674,
            'actual_net_sales' => null,
        ]);
        $this->assertSame(568_049_516, (int) $plan->fresh()->forecast_net_sales);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function input(): array
    {
        return [
            'period_number' => 21, 'fiscal_year' => 2024, 'net_sales' => 100_000_000,
            'cost_of_sales' => 60_000_000, 'selling_general_admin_expenses' => 30_000_000,
            'non_operating_income' => 2_000_000, 'non_operating_expenses' => 1_000_000,
            'interest_expense' => 500_000,
            'extraordinary_income' => 0, 'extraordinary_losses' => 0, 'income_taxes' => 3_000_000,
        ];
    }

    private function companyOwner(): array
    {
        $user = User::factory()->create();
        $organization = Organization::create(['name' => 'Test Company', 'slug' => 'test-'.uniqid()]);
        $workspace = Workspace::create([
            'organization_id' => $organization->id, 'owner_user_id' => $user->id,
            'name' => 'Main', 'slug' => 'main-'.uniqid(), 'status' => Workspace::STATUS_ACTIVE,
        ]);
        $organization->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$user, $organization, ['access_mode' => 'workspace', 'current_workspace_id' => $workspace->id]];
    }
}
