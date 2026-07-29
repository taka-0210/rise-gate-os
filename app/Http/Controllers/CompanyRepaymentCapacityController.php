<?php

namespace App\Http\Controllers;

use App\Models\CompanyAnnualPlan;
use App\Models\CompanyDepreciationPeriod;
use App\Models\CompanyFinancialPeriod;
use App\Models\CompanyLoan;
use App\Models\CompanyRepaymentScenario;
use App\Models\OrganizationUser;
use App\Services\Company\CompanyAccess;
use App\Services\Company\RepaymentCapacityService;
use App\Services\Company\RepaymentCapacityAnalysisService;
use App\Services\Company\RepaymentScenarioService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CompanyRepaymentCapacityController extends Controller
{
    public function index(
        Request $request,
        CompanyAccess $access,
        RepaymentCapacityService $capacity,
        RepaymentCapacityAnalysisService $analysis,
        RepaymentScenarioService $scenarioService,
    ): View {
        [$organization, $canManage, $canManageDebt] = $this->context($request, $access);
        $closingMonth = $organization->fiscal_year_end_month ?: 12;
        $now = CarbonImmutable::now('Asia/Tokyo');
        $currentFiscalYear = $closingMonth === 12 || $now->month > $closingMonth
            ? $now->year
            : $now->year - 1;

        $financialPeriods = CompanyFinancialPeriod::query()
            ->where('organization_id', $organization->id)
            ->where('status', CompanyFinancialPeriod::STATUS_ACTUAL)
            ->where('record_status', CompanyFinancialPeriod::RECORD_CONFIRMED)
            ->get()
            ->keyBy('fiscal_year');
        $annualPlan = CompanyAnnualPlan::query()
            ->where('organization_id', $organization->id)
            ->where('fiscal_year', $currentFiscalYear)
            ->first();
        $hasForecast = $annualPlan && collect([
            $annualPlan->forecast_net_sales,
            $annualPlan->forecast_net_income,
            $annualPlan->forecast_interest_expense,
            $annualPlan->forecast_depreciation_expense,
        ])->contains(fn ($value) => $value !== null);
        $depreciationPeriods = CompanyDepreciationPeriod::query()
            ->where('organization_id', $organization->id)
            ->get()
            ->keyBy('fiscal_year');

        $latestActualYear = $financialPeriods->keys()->map(fn ($year) => (int) $year)->max();
        $firstUnreportedYear = $latestActualYear && $latestActualYear < $currentFiscalYear
            ? $latestActualYear + 1
            : $currentFiscalYear;
        $planEndYear = $currentFiscalYear + 2;

        $years = $financialPeriods->keys()
            ->when($annualPlan, fn ($years) => $years->push($annualPlan->fiscal_year))
            ->merge($depreciationPeriods->keys()->filter(fn ($year) => (int) $year <= $planEndYear))
            ->merge(range($firstUnreportedYear, $planEndYear))
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
            $currentFiscalYear,
            $analysis,
            $annualPlan,
            $hasForecast,
        ): array {
            $financialPeriod = $financialPeriods->get($year);
            $depreciation = $depreciationPeriods->get($year);
            $isCurrentPlan = $year === $currentFiscalYear && $annualPlan;
            $annualValue = function (string $field) use ($annualPlan, $hasForecast) {
                $forecast = data_get($annualPlan, 'forecast_'.$field);

                return $hasForecast && $forecast !== null
                    ? $forecast
                    : data_get($annualPlan, 'plan_'.$field);
            };
            $netIncome = $isCurrentPlan
                ? $annualValue('net_income')
                : ($financialPeriod ? (int) $financialPeriod->net_income : null);
            $depreciationExpense = $isCurrentPlan
                ? $annualValue('depreciation_expense')
                : ($depreciation ? (int) $depreciation->depreciation_expense : null);
            $interestExpense = $isCurrentPlan
                ? $annualValue('interest_expense')
                : ($financialPeriod?->interest_expense !== null ? (int) $financialPeriod->interest_expense : null);
            $principalDetails = $capacity->annualPrincipalRepaymentDetails(
                $organization->id,
                $year,
                $closingMonth,
            );
            $principalRepayment = $principalDetails['total'];
            $assessment = $analysis->analyze(
                $netIncome,
                $depreciationExpense,
                $interestExpense,
                $principalRepayment,
                $principalDetails['extra_total'],
                $principalDetails['refinanced_total'],
            );

            return array_merge($assessment, [
                'year' => $year,
                'period_number' => $isCurrentPlan ? $annualPlan->period_number : $financialPeriod?->period_number,
                'type' => $isCurrentPlan
                    ? ($hasForecast ? '最新見込' : '年度計画')
                    : ($financialPeriod
                        ? '実績'
                        : ($year < $currentFiscalYear ? '未登録' : ($year === $currentFiscalYear ? '今期' : '計画'))),
                'net_income' => $netIncome,
                'depreciation_expense' => $depreciationExpense,
                'interest_expense' => $interestExpense,
                'principal_repayment' => $principalRepayment,
                'scheduled_principal_repayment' => $principalDetails['scheduled_total'],
                'extra_principal_repayment' => $principalDetails['extra_total'],
                'refinanced_principal_repayment' => $principalDetails['refinanced_total'],
                'principal_repayment_loans' => $principalDetails['loans'],
            ]);
        });

        $currentRow = $rows->firstWhere('year', $currentFiscalYear);
        $simulationSourceType = $annualPlan
            ? ($hasForecast ? '03 今年度計画と進捗「最新見込」' : '03 今年度計画と進捗「年度計画」')
            : '03 今年度計画と進捗（未登録）';
        $scenario = CompanyRepaymentScenario::query()
            ->where('organization_id', $organization->id)
            ->where('fiscal_year', $currentFiscalYear)
            ->first();
        if ($scenario) {
            $simulationSourceType = '保存済みシナリオ（元：'.$simulationSourceType.'）';
        }
        $simulationBase = [
            'fiscal_year' => $currentFiscalYear,
            'net_sales' => $annualPlan
                ? ($hasForecast && $annualPlan->forecast_net_sales !== null
                    ? $annualPlan->forecast_net_sales
                    : $annualPlan->plan_net_sales)
                : null,
            'net_income' => data_get($currentRow, 'net_income'),
            'depreciation_expense' => data_get($currentRow, 'depreciation_expense'),
            'interest_expense' => data_get($currentRow, 'interest_expense'),
            'execute_extra_repayments' => true,
            'extra_repayment_funding_overrides' => collect(data_get($currentRow, 'principal_repayment_loans', []))
                ->filter(fn (array $loan) => $loan['includes_extra_repayment'])
                ->mapWithKeys(fn (array $loan) => [(string) $loan['id'] => $loan['extra_repayment_funding']])
                ->all(),
            'new_loans' => [[
                'amount' => 0,
                'executed_on' => null,
                'term_months' => 60,
                'annual_interest_rate' => 0,
                'repayment_mode' => 'amortizing',
            ]],
        ];
        $simulationDefaults = $simulationBase;
        if ($scenario) {
            $simulationDefaults = array_merge($simulationDefaults, [
                'net_sales' => $scenario->net_sales,
                'net_income' => $scenario->net_income,
                'depreciation_expense' => $scenario->depreciation_expense,
                'interest_expense' => $scenario->interest_expense,
                'execute_extra_repayments' => $scenario->execute_extra_repayments,
                'extra_repayment_funding_overrides' => $scenario->extra_repayment_funding_overrides ?? [],
                'new_loans' => $scenario->new_loans ?? $simulationBase['new_loans'],
            ]);
            $scenarioResult = $scenarioService->simulate(
                $organization->id,
                $currentFiscalYear,
                $closingMonth,
                $simulationDefaults,
            );
            $rows = $rows->map(function (array $row) use ($currentFiscalYear, $scenarioResult): array {
                if ($row['year'] !== $currentFiscalYear) {
                    return $row;
                }

                return array_merge($row, $scenarioResult, [
                    'type' => '今期計画',
                    'simulation_saved' => true,
                ]);
            });
        }

        return view('company-finance.repayment-capacity', compact(
            'organization',
            'canManage',
            'canManageDebt',
            'closingMonth',
            'rows',
            'currentFiscalYear',
            'simulationBase',
            'simulationDefaults',
            'simulationSourceType',
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

    public function updateExtraRepaymentFunding(
        Request $request,
        CompanyLoan $loan,
        CompanyAccess $access,
    ): RedirectResponse {
        $organization = $request->attributes->get('currentCompany');
        abort_unless(
            $loan->organization_id === $organization->id
            && $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_DEBT),
            403,
        );
        $validated = $request->validate([
            'extra_repayment_funding' => ['required', Rule::in([
                CompanyLoan::EXTRA_REPAYMENT_SELF_FUNDED,
                CompanyLoan::EXTRA_REPAYMENT_REFINANCE,
            ])],
        ]);

        $loan->update($validated);

        return back()->with('status', '一括返済の資金区分を更新しました。');
    }

    public function simulate(
        Request $request,
        CompanyAccess $access,
        RepaymentScenarioService $scenarioService,
    ): JsonResponse {
        [$organization] = $this->scenarioManageContext($request, $access);
        $validated = $request->validate($this->scenarioRules());
        $this->assertCurrentFiscalYear(
            $organization->fiscal_year_end_month ?: 12,
            (int) $validated['fiscal_year'],
        );
        $this->assertLoanOverridesBelongToOrganization(
            $organization->id,
            $validated['extra_repayment_funding_overrides'] ?? [],
        );

        return response()->json($scenarioService->simulate(
            $organization->id,
            (int) $validated['fiscal_year'],
            $organization->fiscal_year_end_month ?: 12,
            $validated,
        ));
    }

    public function saveScenario(
        Request $request,
        CompanyAccess $access,
        RepaymentScenarioService $scenarioService,
    ): JsonResponse {
        [$organization] = $this->scenarioManageContext($request, $access);
        $validated = $request->validate($this->scenarioRules());
        $fiscalYear = (int) $validated['fiscal_year'];
        $this->assertCurrentFiscalYear($organization->fiscal_year_end_month ?: 12, $fiscalYear);
        $this->assertLoanOverridesBelongToOrganization(
            $organization->id,
            $validated['extra_repayment_funding_overrides'] ?? [],
        );
        CompanyRepaymentScenario::updateOrCreate(
            ['organization_id' => $organization->id, 'fiscal_year' => $fiscalYear],
            [
                'net_sales' => $validated['net_sales'] ?? null,
                'net_income' => $validated['net_income'] ?? null,
                'depreciation_expense' => $validated['depreciation_expense'] ?? null,
                'interest_expense' => $validated['interest_expense'] ?? null,
                'execute_extra_repayments' => $validated['execute_extra_repayments'],
                'extra_repayment_funding_overrides' => $validated['extra_repayment_funding_overrides'] ?? [],
                'new_loans' => $validated['new_loans'] ?? [],
                'updated_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'message' => '今期の経営判断シナリオを保存しました。',
            'result' => $scenarioService->simulate(
                $organization->id,
                $fiscalYear,
                $organization->fiscal_year_end_month ?: 12,
                $validated,
            ),
        ]);
    }

    private function scenarioRules(): array
    {
        return [
            'fiscal_year' => ['required', 'integer', 'between:1900,2200'],
            'net_sales' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'net_income' => ['nullable', 'integer', 'between:-999999999999,999999999999'],
            'depreciation_expense' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'interest_expense' => ['nullable', 'integer', 'min:0', 'max:999999999999'],
            'execute_extra_repayments' => ['required', 'boolean'],
            'extra_repayment_funding_overrides' => ['nullable', 'array'],
            'extra_repayment_funding_overrides.*' => ['required', Rule::in([
                CompanyLoan::EXTRA_REPAYMENT_SELF_FUNDED,
                CompanyLoan::EXTRA_REPAYMENT_REFINANCE,
            ])],
            'new_loans' => ['nullable', 'array', 'max:10'],
            'new_loans.*.amount' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'new_loans.*.executed_on' => ['nullable', 'date'],
            'new_loans.*.term_months' => ['required', 'integer', 'between:1,600'],
            'new_loans.*.annual_interest_rate' => ['required', 'numeric', 'between:0,100'],
            'new_loans.*.repayment_mode' => ['required', Rule::in(['amortizing', 'bullet'])],
        ];
    }

    private function scenarioManageContext(Request $request, CompanyAccess $access): array
    {
        $organization = $request->attributes->get('currentCompany');
        abort_unless(
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_PL)
            && $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_DEBT),
            403,
        );

        return [$organization, true];
    }

    private function assertCurrentFiscalYear(int $closingMonth, int $fiscalYear): void
    {
        $now = CarbonImmutable::now('Asia/Tokyo');
        $currentFiscalYear = $closingMonth === 12 || $now->month > $closingMonth
            ? $now->year
            : $now->year - 1;
        abort_unless($fiscalYear === $currentFiscalYear, 422);
    }

    private function assertLoanOverridesBelongToOrganization(int $organizationId, array $overrides): void
    {
        $ids = collect(array_keys($overrides))->map(fn ($id) => (int) $id)->filter();
        if ($ids->isEmpty()) {
            return;
        }
        abort_unless(
            CompanyLoan::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $ids)
                ->count() === $ids->unique()->count(),
            422,
        );
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
            $access->allows($request->user(), $organization, OrganizationUser::PERMISSION_FINANCE_MANAGE_DEBT),
        ];
    }

    private function manageContext(Request $request, CompanyAccess $access): array
    {
        [$organization, $canManage] = $this->context($request, $access);
        abort_unless($canManage, 403);

        return [$organization, true];
    }
}
