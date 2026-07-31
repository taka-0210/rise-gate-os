<?php

namespace Tests\Unit;

use App\Services\Company\AnnualPlanForecastService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class AnnualPlanForecastServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_empty_forecast_stays_empty_instead_of_falling_back_to_plan(): void
    {
        $plan = (object) [
            'plan_net_sales' => 200,
            'plan_gross_profit' => 80,
            'plan_selling_general_admin_expenses' => 70,
        ];
        $months = collect([
            (object) [
                'plan_net_sales' => 100,
                'plan_cost_of_sales' => 60,
                'plan_selling_general_admin_expenses' => 35,
                'forecast_net_sales' => null,
                'forecast_cost_of_sales' => null,
                'forecast_selling_general_admin_expenses' => null,
                'actual_net_sales' => 90,
                'actual_cost_of_sales' => 50,
                'actual_selling_general_admin_expenses' => 30,
            ],
            (object) [
                'plan_net_sales' => 100,
                'plan_cost_of_sales' => 60,
                'plan_selling_general_admin_expenses' => 35,
                'forecast_net_sales' => null,
                'forecast_cost_of_sales' => null,
                'forecast_selling_general_admin_expenses' => null,
                'actual_net_sales' => null,
                'actual_cost_of_sales' => null,
                'actual_selling_general_admin_expenses' => null,
            ],
        ]);

        $result = (new AnnualPlanForecastService())->calculate($plan, $months);

        $this->assertSame(90, $result['forecast_sales']);
        $this->assertSame(40, $result['forecast_gross_profit']);
        $this->assertSame(30, $result['forecast_sga']);
    }

    public function test_past_month_without_actuals_is_not_treated_as_a_forecast_in_jst(): void
    {
        CarbonImmutable::setTestNow('2026-08-01 00:05:00 Asia/Tokyo');
        $plan = (object) [
            'plan_net_sales' => 300,
            'plan_gross_profit' => 120,
            'plan_selling_general_admin_expenses' => 90,
        ];
        $months = collect([
            (object) [
                'month' => '2026-07-01',
                'plan_net_sales' => 100,
                'plan_cost_of_sales' => 60,
                'plan_selling_general_admin_expenses' => 30,
                'forecast_net_sales' => 100,
                'forecast_cost_of_sales' => 60,
                'forecast_selling_general_admin_expenses' => 30,
                'actual_net_sales' => null,
                'actual_cost_of_sales' => null,
                'actual_selling_general_admin_expenses' => null,
            ],
            (object) [
                'month' => '2026-08-01',
                'plan_net_sales' => 200,
                'plan_cost_of_sales' => 120,
                'plan_selling_general_admin_expenses' => 60,
                'forecast_net_sales' => 200,
                'forecast_cost_of_sales' => 120,
                'forecast_selling_general_admin_expenses' => 60,
                'actual_net_sales' => null,
                'actual_cost_of_sales' => null,
                'actual_selling_general_admin_expenses' => null,
            ],
        ]);

        $result = (new AnnualPlanForecastService())->calculate($plan, $months);

        $this->assertSame(200, $result['forecast_sales']);
        $this->assertSame(80, $result['forecast_gross_profit']);
        $this->assertSame(60, $result['forecast_sga']);
    }
}
