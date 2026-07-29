<?php

namespace App\Services\Company;

use Carbon\CarbonImmutable;

class RepaymentScenarioService
{
    public function __construct(
        private readonly RepaymentCapacityService $capacity,
        private readonly RepaymentCapacityAnalysisService $analysis,
    ) {
    }

    public function simulate(
        int $organizationId,
        int $fiscalYear,
        int $closingMonth,
        array $input,
    ): array {
        [$start, $end] = $this->fiscalPeriod($fiscalYear, $closingMonth);
        $principalDetails = $this->capacity->annualPrincipalRepaymentDetails(
            $organizationId,
            $fiscalYear,
            $closingMonth,
            [
                'execute_extra_repayments' => (bool) ($input['execute_extra_repayments'] ?? true),
                'extra_repayment_funding_overrides' => $input['extra_repayment_funding_overrides'] ?? [],
            ],
        );

        $newLoanTotals = collect($input['new_loans'] ?? [])
            ->filter(fn (array $loan) => (int) ($loan['amount'] ?? 0) > 0)
            ->map(fn (array $loan) => $this->newLoanImpact($loan, $start, $end));
        $newLoanPrincipal = (int) $newLoanTotals->sum('principal_repayment');
        $newLoanInterest = (int) $newLoanTotals->sum('interest_expense');
        $existingInterestExpense = isset($input['interest_expense'])
            ? (int) $input['interest_expense']
            : 0;
        $interestExpense = $existingInterestExpense + $newLoanInterest;
        $principalRepayment = $principalDetails['total'] + $newLoanPrincipal;
        $plannedNetIncome = isset($input['net_income']) ? (int) $input['net_income'] : null;
        $projectedNetIncome = $plannedNetIncome !== null
            ? $plannedNetIncome - $newLoanInterest
            : null;

        $analysis = $this->analysis->analyze(
            $projectedNetIncome,
            isset($input['depreciation_expense']) ? (int) $input['depreciation_expense'] : null,
            $interestExpense,
            $principalRepayment,
            $principalDetails['extra_total'],
            $principalDetails['refinanced_total'],
            $newLoanPrincipal,
        );

        return array_merge($analysis, [
            'net_sales' => isset($input['net_sales']) ? (int) $input['net_sales'] : null,
            'net_income_plan' => $plannedNetIncome,
            'net_income' => $projectedNetIncome,
            'depreciation_expense' => isset($input['depreciation_expense'])
                ? (int) $input['depreciation_expense']
                : null,
            'interest_expense' => $interestExpense,
            'existing_interest_expense' => $existingInterestExpense,
            'new_loan_interest_expense' => $newLoanInterest,
            'principal_repayment' => $principalRepayment,
            'scheduled_principal_repayment' => $principalDetails['scheduled_total'],
            'extra_principal_repayment' => $principalDetails['extra_total'],
            'refinanced_principal_repayment' => $principalDetails['refinanced_total'],
            'postponed_extra_repayment' => $principalDetails['postponed_extra_total'],
            'new_loan_principal_repayment' => $newLoanPrincipal,
            'principal_repayment_loans' => $principalDetails['loans'],
            'new_loan_impacts' => $newLoanTotals->values()->all(),
        ]);
    }

    private function newLoanImpact(
        array $loan,
        CarbonImmutable $fiscalStart,
        CarbonImmutable $fiscalEnd,
    ): array {
        $amount = max(0, (int) ($loan['amount'] ?? 0));
        $termMonths = max(1, (int) ($loan['term_months'] ?? 60));
        $rate = max(0, (float) ($loan['annual_interest_rate'] ?? 0));
        $mode = ($loan['repayment_mode'] ?? 'amortizing') === 'bullet' ? 'bullet' : 'amortizing';
        $executedOn = ! empty($loan['executed_on'])
            ? CarbonImmutable::parse($loan['executed_on'], 'Asia/Tokyo')->startOfMonth()
            : $fiscalStart;
        $firstPaymentMonth = $executedOn->addMonth();
        $monthlyPrincipal = $mode === 'amortizing'
            ? (int) ceil($amount / $termMonths)
            : 0;
        $maturityMonth = $executedOn->addMonths($termMonths);
        $principal = 0;
        $interest = 0.0;
        $balance = $amount;

        for (
            $month = $executedOn->greaterThan($fiscalStart) ? $executedOn : $fiscalStart;
            $month->lessThanOrEqualTo($fiscalEnd);
            $month = $month->addMonth()
        ) {
            if ($month->greaterThanOrEqualTo($executedOn)) {
                $interest += $balance * ($rate / 100) / 12;
            }
            if ($month->lessThan($firstPaymentMonth)) {
                continue;
            }
            $payment = $mode === 'bullet'
                ? ($month->greaterThanOrEqualTo($maturityMonth) ? $balance : 0)
                : min($monthlyPrincipal, $balance);
            $principal += $payment;
            $balance -= $payment;
        }

        return [
            'amount' => $amount,
            'executed_on' => $executedOn->toDateString(),
            'term_months' => $termMonths,
            'annual_interest_rate' => $rate,
            'repayment_mode' => $mode,
            'monthly_principal_payment' => $monthlyPrincipal,
            'principal_repayment' => $principal,
            'interest_expense' => (int) round($interest),
            'ending_balance' => $balance,
        ];
    }

    private function fiscalPeriod(int $fiscalYear, int $closingMonth): array
    {
        $startMonth = $closingMonth === 12 ? 1 : $closingMonth + 1;
        $start = CarbonImmutable::create($fiscalYear, $startMonth, 1, 0, 0, 0, 'Asia/Tokyo');

        return [$start, $start->addYear()->subDay()->endOfDay()];
    }
}
