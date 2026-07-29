<?php

namespace App\Http\Controllers;

use App\Models\CompanyAnnualPlan;
use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyAnnualPlanController extends Controller
{
    public function index(Request $request, CompanyAccess $access): View
    {
        [$organization, $canManage] = $this->context($request, $access);
        $fiscalYear = $this->currentFiscalYear($organization->fiscal_year_end_month ?: 12);
        $plan = CompanyAnnualPlan::query()
            ->where('organization_id', $organization->id)
            ->where('fiscal_year', $fiscalYear)
            ->first();

        return view('company-finance.annual-plan', compact(
            'organization',
            'canManage',
            'fiscalYear',
            'plan',
        ));
    }

    public function update(Request $request, CompanyAccess $access): RedirectResponse
    {
        [$organization, $canManage] = $this->context($request, $access);
        abort_unless($canManage, 403);
        $fiscalYear = $this->currentFiscalYear($organization->fiscal_year_end_month ?: 12);
        $rules = [
            'period_number' => ['nullable', 'integer', 'between:1,999'],
        ];
        foreach (['plan', 'forecast'] as $kind) {
            foreach ([
                'net_sales',
                'gross_profit',
                'selling_general_admin_expenses',
                'net_income',
                'interest_expense',
                'depreciation_expense',
            ] as $field) {
                $rules[$kind.'_'.$field] = [
                    'nullable',
                    'integer',
                    $field === 'net_income' ? 'between:-999999999999,999999999999' : 'between:0,999999999999',
                ];
            }
        }
        $validated = $request->validate($rules);

        CompanyAnnualPlan::updateOrCreate(
            ['organization_id' => $organization->id, 'fiscal_year' => $fiscalYear],
            array_merge($validated, ['updated_by' => $request->user()->id]),
        );

        return back()->with('status', '今年度の計画・最新見込を保存しました。');
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
