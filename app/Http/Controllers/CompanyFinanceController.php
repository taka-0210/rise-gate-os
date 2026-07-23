<?php

namespace App\Http\Controllers;

use App\Models\CompanyFinancialPeriod;
use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyFinanceController extends Controller
{
    public function index(Request $request, CompanyAccess $access): View
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless(
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_PL),
            403
        );
        $periods = CompanyFinancialPeriod::query()
            ->where('organization_id', $organization->id)
            ->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)
            ->orderByDesc('fiscal_year')
            ->get();

        $chronological = $periods->sortBy('fiscal_year')->values();
        $latest = $periods->first();
        $previous = $periods->get(1);
        $highestSales = $periods->sortByDesc('net_sales')->first();

        return view('company-finance.index', [
            'organization' => $organization,
            'periods' => $periods,
            'latest' => $latest,
            'previous' => $previous,
            'highestSales' => $highestSales,
            'profitablePeriodCount' => $periods->where('operating_profit', '>', 0)->count(),
            'salesGrowthRate' => $this->growthRate($latest?->net_sales, $previous?->net_sales),
            'latestNetIncomeRatio' => $latest && $latest->net_sales
                ? ($latest->net_income / $latest->net_sales) * 100
                : null,
            'chartPeriods' => $chronological,
            'amountSeries' => [
                ['label' => '売上高', 'color' => '#165d6c', 'values' => $chronological->pluck('net_sales')->map(fn ($value) => (float) $value)->all()],
                ['label' => '売上総利益', 'color' => '#4c91a0', 'values' => $chronological->pluck('gross_profit')->map(fn ($value) => (float) $value)->all()],
                ['label' => '販管費', 'color' => '#be8a5a', 'values' => $chronological->pluck('selling_general_admin_expenses')->map(fn ($value) => (float) $value)->all()],
            ],
            'profitSeries' => [
                ['label' => '営業利益', 'color' => '#16705c', 'values' => $chronological->pluck('operating_profit')->map(fn ($value) => (float) $value)->all()],
                ['label' => '経常利益', 'color' => '#5d76aa', 'values' => $chronological->pluck('ordinary_profit')->map(fn ($value) => (float) $value)->all()],
                ['label' => '当期純利益', 'color' => '#9a5f7d', 'values' => $chronological->pluck('net_income')->map(fn ($value) => (float) $value)->all()],
            ],
            'marginSeries' => [
                ['label' => '粗利率', 'color' => '#165d6c', 'values' => $chronological->map(fn ($period) => (float) $period->gross_profit_ratio * 100)->all()],
                ['label' => '営業利益率', 'color' => '#16705c', 'values' => $chronological->map(fn ($period) => (float) $period->operating_profit_ratio * 100)->all()],
                ['label' => '最終利益率', 'color' => '#9a5f7d', 'values' => $chronological->map(fn ($period) => $period->net_sales ? ($period->net_income / $period->net_sales) * 100 : 0)->all()],
            ],
        ]);
    }

    private function growthRate(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || ! $previous) {
            return null;
        }

        return (($current - $previous) / abs($previous)) * 100;
    }
}
