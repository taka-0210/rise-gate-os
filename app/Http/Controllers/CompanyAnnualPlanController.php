<?php

namespace App\Http\Controllers;

use App\Models\CompanyAnnualPlan;
use App\Models\OrganizationUser;
use App\Services\Company\AnnualPlanForecastService;
use App\Services\Company\CompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CompanyAnnualPlanController extends Controller
{
    public function index(
        Request $request,
        CompanyAccess $access,
        AnnualPlanForecastService $forecastService,
    ): View {
        [$organization, $canManage] = $this->context($request, $access);
        $fiscalYear = $this->currentFiscalYear($organization->fiscal_year_end_month ?: 12);
        $plan = CompanyAnnualPlan::query()
            ->where('organization_id', $organization->id)
            ->where('fiscal_year', $fiscalYear)
            ->with('months')
            ->first();
        $months = $this->monthRows(
            $fiscalYear,
            $organization->fiscal_year_end_month ?: 12,
            $plan,
        );
        $forecast = $plan ? $forecastService->calculate($plan, $months) : null;

        return view('company-finance.annual-plan', compact(
            'organization',
            'canManage',
            'fiscalYear',
            'plan',
            'months',
            'forecast',
        ));
    }

    public function update(
        Request $request,
        CompanyAccess $access,
        AnnualPlanForecastService $forecastService,
    ): RedirectResponse {
        [$organization, $canManage] = $this->context($request, $access);
        abort_unless($canManage, 403);
        $fiscalYear = $this->currentFiscalYear($organization->fiscal_year_end_month ?: 12);
        $rules = ['period_number' => ['nullable', 'integer', 'between:1,999']];

        foreach ([
            'net_sales',
            'gross_profit',
            'selling_general_admin_expenses',
            'net_income',
            'interest_expense',
            'depreciation_expense',
        ] as $field) {
            $rules['plan_'.$field] = [
                'nullable',
                'integer',
                $field === 'net_income'
                    ? 'between:-999999999999,999999999999'
                    : 'between:0,999999999999',
            ];
        }
        foreach (['net_income', 'interest_expense', 'depreciation_expense'] as $field) {
            $rules['forecast_'.$field] = [
                'nullable',
                'integer',
                $field === 'net_income'
                    ? 'between:-999999999999,999999999999'
                    : 'between:0,999999999999',
            ];
        }
        $rules['months'] = ['required', 'array', 'size:12'];
        $rules['months.*.month'] = ['required', 'date_format:Y-m-d'];
        foreach ([
            'plan_net_sales',
            'plan_cost_of_sales',
            'plan_selling_general_admin_expenses',
            'actual_net_sales',
            'actual_cost_of_sales',
            'actual_selling_general_admin_expenses',
        ] as $field) {
            $rules['months.*.'.$field] = [
                'nullable',
                'integer',
                'between:0,999999999999',
            ];
        }
        $validated = $request->validate($rules);

        $expectedMonths = $this->fiscalMonths(
            $fiscalYear,
            $organization->fiscal_year_end_month ?: 12,
        )->map->format('Y-m-d')->sort()->values()->all();
        abort_unless(
            collect($validated['months'])->pluck('month')->sort()->values()->all() === $expectedMonths,
            422,
        );

        DB::transaction(function () use ($validated, $organization, $fiscalYear, $request, $forecastService): void {
            $plan = CompanyAnnualPlan::updateOrCreate(
                ['organization_id' => $organization->id, 'fiscal_year' => $fiscalYear],
                array_merge(
                    collect($validated)->except('months')->all(),
                    ['updated_by' => $request->user()->id],
                ),
            );

            foreach ($validated['months'] as $month) {
                $plan->months()->updateOrCreate(
                    ['month' => $month['month']],
                    collect($month)->except('month')->all(),
                );
            }

            $forecast = $forecastService->calculate($plan, $plan->months()->get());
            $plan->update([
                'forecast_net_sales' => $forecast['forecast_sales'],
                'forecast_gross_profit' => $forecast['forecast_gross_profit'],
                'forecast_selling_general_admin_expenses' => $forecast['forecast_sga'],
            ]);
        });

        return back()->with('status', '今年度の計画・月次進捗・最新見込を保存しました。');
    }

    private function monthRows(
        int $fiscalYear,
        int $closingMonth,
        ?CompanyAnnualPlan $plan,
    ): Collection {
        $saved = $plan?->months?->keyBy(fn ($month) => $month->month->format('Y-m-d')) ?? collect();
        $annualSales = (int) ($plan?->plan_net_sales ?? 0);
        $annualCost = $annualSales - (int) ($plan?->plan_gross_profit ?? 0);
        $annualSga = (int) ($plan?->plan_selling_general_admin_expenses ?? 0);
        $monthlySalesBase = intdiv($annualSales, 12);
        $monthlyCostBase = intdiv($annualCost, 12);
        $monthlySgaBase = intdiv($annualSga, 12);

        return $this->fiscalMonths($fiscalYear, $closingMonth)
            ->values()
            ->map(function (CarbonImmutable $month, int $index) use (
                $saved,
                $monthlySalesBase,
                $monthlyCostBase,
                $monthlySgaBase,
                $annualSales,
                $annualCost,
                $annualSga,
            ) {
                $key = $month->format('Y-m-d');
                if ($saved->has($key)) {
                    return $saved->get($key);
                }

                return (object) [
                    'month' => $month,
                    'plan_net_sales' => $index === 11
                        ? $annualSales - ($monthlySalesBase * 11)
                        : $monthlySalesBase,
                    'plan_cost_of_sales' => $index === 11
                        ? $annualCost - ($monthlyCostBase * 11)
                        : $monthlyCostBase,
                    'plan_selling_general_admin_expenses' => $index === 11
                        ? $annualSga - ($monthlySgaBase * 11)
                        : $monthlySgaBase,
                    'actual_net_sales' => null,
                    'actual_cost_of_sales' => null,
                    'actual_selling_general_admin_expenses' => null,
                ];
            });
    }

    private function fiscalMonths(int $fiscalYear, int $closingMonth): Collection
    {
        $startMonth = $closingMonth === 12 ? 1 : $closingMonth + 1;
        $start = CarbonImmutable::create(
            $fiscalYear,
            $startMonth,
            1,
            0,
            0,
            0,
            'Asia/Tokyo',
        );

        return collect(range(0, 11))->map(fn (int $offset) => $start->addMonths($offset));
    }

    private function context(Request $request, CompanyAccess $access): array
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless(
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_PL),
            403,
        );

        return [
            $organization,
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_PL),
        ];
    }

    private function currentFiscalYear(int $closingMonth): int
    {
        $now = CarbonImmutable::now('Asia/Tokyo');

        return $closingMonth === 12 || $now->month > $closingMonth
            ? $now->year
            : $now->year - 1;
    }
}
