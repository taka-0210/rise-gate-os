<?php

namespace App\Http\Controllers;

use App\Models\CompanyDepreciationPeriod;
use App\Models\CompanyFinancialPeriod;
use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use App\Services\Company\RepaymentCapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CompanyRepaymentCapacityController extends Controller
{
    public function index(
        Request $request,
        CompanyAccess $access,
        RepaymentCapacityService $capacity,
    ): View {
        [$organization, $canManage] = $this->context($request, $access);
        $closingMonth = $organization->fiscal_year_end_month ?: 12;
        $currentFiscalYear = CarbonImmutable::now('Asia/Tokyo')->month > $closingMonth
            ? CarbonImmutable::now('Asia/Tokyo')->year + 1
            : CarbonImmutable::now('Asia/Tokyo')->year;

        $financialPeriods = CompanyFinancialPeriod::query()
            ->where('organization_id', $organization->id)
            ->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)
            ->where('record_status', CompanyFinancialPeriod::RECORD_CONFIRMED)
            ->get()
            ->keyBy('fiscal_year');
        $depreciationPeriods = CompanyDepreciationPeriod::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy('fiscal_year');

        $years = $financialPeriods->keys()
            ->merge($depreciationPeriods->keys())
            ->merge(range($currentFiscalYear, $currentFiscalYear + 9))
            ->map(fn ($year) => (int) $year)
            ->unique()
            ->sortDesc()
            ->values();

        $rows = $years->map(function (int $year) use (
            $organization,
            $closingMonth,
            $financialPeriods,
            $depreciationPeriods,
            $capacity,
        ): array {
            $financialPeriod = $financialPeriods->get($year);
            $depreciation = $depreciationPeriods->get($year);
            $netIncome = $financialPeriod ? (int) $financialPeriod->net_income : null;
            $depreciationExpense = $depreciation ? (int) $depreciation->depreciation_expense : null;
            $principalRepayment = $capacity->annualPrincipalRepayment(
                $organization->id,
                $year,
                $closingMonth,
            );
            $repaymentSource = $netIncome !== null && $depreciationExpense !== null
                ? $netIncome + $depreciationExpense
                : null;

            return [
                'year' => $year,
                'period_number' => $financialPeriod?->period_number,
                'type' => $financialPeriod ? '実績' : '計画',
                'net_income' => $netIncome,
                'depreciation_expense' => $depreciationExpense,
                'repayment_source' => $repaymentSource,
                'principal_repayment' => $principalRepayment,
                'remaining_capacity' => $repaymentSource !== null ? $repaymentSource - $principalRepayment : null,
                'coverage_ratio' => $repaymentSource !== null && $principalRepayment > 0
                    ? $repaymentSource / $principalRepayment
                    : null,
            ];
        });

        return view('company-finance.repayment-capacity', compact(
            'organization',
            'canManage',
            'closingMonth',
            'rows',
        ));
    }

    public function update(Request $request, CompanyAccess $access): RedirectResponse
    {
        [$organization] = $this->manageContext($request, $access);
        $validated = $request->validate([
            'depreciation' => ['required', 'array'],
            'depreciation.*' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
        ]);

        DB::transaction(function () use ($validated, $organization, $request): void {
            foreach ($validated['depreciation'] as $year => $amount) {
                abort_unless(filter_var($year, FILTER_VALIDATE_INT) !== false && (int) $year >= 1900 && (int) $year <= 2200, 422);

                if ($amount === null || $amount === '') {
                    CompanyDepreciationPeriod::query()
                        ->where('organization_id', $organization->id)
                        ->where('fiscal_year', (int) $year)
                        ->delete();
                    continue;
                }

                CompanyDepreciationPeriod::updateOrCreate(
                    ['organization_id' => $organization->id, 'fiscal_year' => (int) $year],
                    ['depreciation_expense' => (int) $amount, 'updated_by' => $request->user()->id],
                );
            }
        });

        return back()->with('status', '年度別の減価償却費を保存しました。');
    }

    private function context(Request $request, CompanyAccess $access): array
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless(
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_PL)
            && $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_VIEW_DEBT),
            403,
        );

        return [
            $organization,
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_PL),
        ];
    }

    private function manageContext(Request $request, CompanyAccess $access): array
    {
        [$organization, $canManage] = $this->context($request, $access);
        abort_unless($canManage, 403);

        return [$organization, true];
    }
}
