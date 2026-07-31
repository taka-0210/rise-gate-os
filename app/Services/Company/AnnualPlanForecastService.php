<?php

namespace App\Services\Company;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class AnnualPlanForecastService
{
    public function calculate(object $plan, Collection $months): array
    {
        $actualMonths = $months->filter(fn ($month) => $month->actual_net_sales !== null);
        $currentMonth = CarbonImmutable::now('Asia/Tokyo')->startOfMonth();
        $remainingMonths = $months
            ->reject(fn ($month) => $month->actual_net_sales !== null)
            ->filter(function ($month) use ($currentMonth): bool {
                $monthDate = data_get($month, 'month');

                return $monthDate === null
                    || CarbonImmutable::parse($monthDate, 'Asia/Tokyo')->startOfMonth()->greaterThanOrEqualTo($currentMonth);
            });

        $actualSales = (int) $actualMonths->sum('actual_net_sales');
        $actualCost = (int) $actualMonths->sum('actual_cost_of_sales');
        $actualSga = (int) $actualMonths->sum('actual_selling_general_admin_expenses');
        $elapsedPlanSales = (int) $actualMonths->sum('plan_net_sales');
        $remainingPlanSales = (int) $remainingMonths->sum('forecast_net_sales');
        $remainingPlanCost = (int) $remainingMonths->sum('forecast_cost_of_sales');
        $remainingPlanSga = (int) $remainingMonths->sum('forecast_selling_general_admin_expenses');

        $planSales = (int) ($plan->plan_net_sales ?? 0);
        if ($remainingMonths->isNotEmpty() && $remainingMonths->every(fn ($month) => data_get($month, 'plan_cost_of_sales') === null)) {
            $planCost = $planSales - (int) ($plan->plan_gross_profit ?? 0);
            $remainingPlanCost = $planSales > 0
                ? (int) round($remainingPlanSales * ($planCost / $planSales))
                : 0;
        }
        if ($remainingMonths->isNotEmpty() && $remainingMonths->every(fn ($month) => data_get($month, 'plan_selling_general_admin_expenses') === null)) {
            $remainingPlanSga = $months->isNotEmpty()
                ? (int) round(((int) ($plan->plan_selling_general_admin_expenses ?? 0) / $months->count()) * $remainingMonths->count())
                : 0;
        }

        $forecastSales = $actualSales + $remainingPlanSales;
        $forecastGrossProfit = ($actualSales - $actualCost) + ($remainingPlanSales - $remainingPlanCost);
        $forecastSga = $actualSga + $remainingPlanSga;

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
