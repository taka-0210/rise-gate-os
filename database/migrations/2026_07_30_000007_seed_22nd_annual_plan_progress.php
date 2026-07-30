<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $plans = DB::table('company_annual_plans')
            ->where('fiscal_year', 2025)
            ->where('period_number', 22)
            ->where('plan_net_sales', 614_000_000)
            ->where('plan_gross_profit', 273_230_000)
            ->where('plan_selling_general_admin_expenses', 267_230_000)
            ->get(['id']);

        if ($plans->isEmpty()) {
            return;
        }

        $actuals = [
            '2025-12-01' => [43_711_274, 25_238_002],
            '2026-01-01' => [32_498_184, 18_430_569],
            '2026-02-01' => [48_997_473, 27_269_106],
            '2026-03-01' => [41_384_218, 21_838_163],
            '2026-04-01' => [57_627_479, 32_273_964],
            '2026-05-01' => [41_225_972, 24_368_125],
            '2026-06-01' => [46_771_578, 26_276_125],
        ];
        $start = CarbonImmutable::create(2025, 12, 1, 0, 0, 0, 'Asia/Tokyo');
        $salesBase = intdiv(614_000_000, 12);
        $sgaBase = intdiv(267_230_000, 12);
        $now = CarbonImmutable::now('Asia/Tokyo');

        foreach ($plans as $plan) {
            $alreadyStarted = DB::table('company_annual_plan_months')
                ->where('company_annual_plan_id', $plan->id)
                ->exists();
            if ($alreadyStarted) {
                continue;
            }

            foreach (range(0, 11) as $index) {
                $month = $start->addMonths($index)->format('Y-m-d');
                $actual = $actuals[$month] ?? null;

                DB::table('company_annual_plan_months')->insertOrIgnore([
                    'company_annual_plan_id' => $plan->id,
                    'month' => $month,
                    'plan_net_sales' => $index === 11
                        ? 614_000_000 - ($salesBase * 11)
                        : $salesBase,
                    'actual_net_sales' => $actual[0] ?? null,
                    'actual_cost_of_sales' => $actual[1] ?? null,
                    // The source workbook's freee link was broken. Use the annual
                    // SGA plan divided by 12 as an editable provisional amount.
                    'actual_selling_general_admin_expenses' => $actual
                        ? ($index === 11 ? 267_230_000 - ($sgaBase * 11) : $sgaBase)
                        : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('company_annual_plans')->where('id', $plan->id)->update([
                'forecast_net_sales' => 568_049_516,
                'forecast_gross_profit' => 250_367_959,
                'forecast_selling_general_admin_expenses' => 267_229_995,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Initial business data may have been reviewed and edited after deployment.
        // Do not delete it automatically during rollback.
    }
};
