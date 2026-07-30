<?php

namespace App\Services\Company;

use Illuminate\Support\Collection;

class AnnualPlanForecastService
{
    public function calculate(object $plan, Collection $months): array
    {
        $actualMonths = $months->filter(fn ($month) => $month->actual_net_sales !== null);
        $remainingMonths = $months->reject(fn ($month) => $month->actual_net_sales !== null);

        $actualSales = (int) $actualMonths->sum('actual_net_sales');
        $actualCost = (int) $actualMonths->sum('actual_cost_of_sales');
        $actualSga = (int) $actualMonths->sum('actual_selling_general_admin_expenses');
        $elapsedPlanSales = (int) $actualMonths->sum('plan_net_sales');
        $remainingPlanSales = (int) $remainingMonths->sum('plan_net_sales');

        $planSales = (int) ($plan->plan_net_sales ?? 0);
        $planGrossProfit = (int) ($plan->plan_gross_profit ?? 0);
        $grossMargin = $planSales > 0 ? $planGrossProfit / $planSales : 0.0;
        $remainingSga = $months->isNotEmpty()
            ? (int) round(((int) ($plan->plan_selling_general_admin_expenses ?? 0) / $months->count()) * $remainingMonths->count())
            : 0;

        $forecastSales = $actualSales + $remainingPlanSales;
        $forecastGrossProfit = ($actualSales - $actualCost) + (int) round($remainingPlanSales * $grossMargin);
        $forecastSga = $actualSga + $remainingSga;

        return [
            'actual_month_count' => $actualMonths->count(),
            'actual_sales' => $actualSales,
            'actual_cost_of_sales' => $actualCost,
            'actual_gross_profit' => $actualSales - $actualCost,
            'actual_sga' => $actualSga,
            'actual_operating_profit' => ($actualSales - $actualCost) - $actualSga,
            'elapsed_plan_sales' => $elapsedPlanSales,
            'sales_variance' => $actualSales - $elapsedPlanSales,
            'sales_achievement_rate' => $elapsedPlanSales > 0 ? $actualSales / $elapsedPlanSales : null,
            'gross_margin' => $actualSales > 0 ? ($actualSales - $actualCost) / $actualSales : null,
            'forecast_sales' => $forecastSales,
            'forecast_gross_profit' => $forecastGrossProfit,
            'forecast_sga' => $forecastSga,
            'forecast_operating_profit' => $forecastGrossProfit - $forecastSga,
            'forecast_sales_variance' => $planSales > 0 ? $forecastSales - $planSales : null,
        ];
    }
}
