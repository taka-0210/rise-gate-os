<?php

namespace Tests\Feature;

use App\Models\CompanyFinancialPeriod;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
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
            ->assertOk()->assertSee('1</b><span>期', false)->assertSee('10</b><span>法人税等', false);
    }

    private function input(): array
    {
        return [
            'period_number' => 21, 'fiscal_year' => 2024, 'net_sales' => 100_000_000,
            'cost_of_sales' => 60_000_000, 'selling_general_admin_expenses' => 30_000_000,
            'non_operating_income' => 2_000_000, 'non_operating_expenses' => 1_000_000,
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
