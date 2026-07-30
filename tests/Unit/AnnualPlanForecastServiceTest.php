<?php

namespace Tests\Unit;

use App\Services\Company\AnnualPlanForecastService;
use PHPUnit\Framework\TestCase;

class AnnualPlanForecastServiceTest extends TestCase
{
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
}
