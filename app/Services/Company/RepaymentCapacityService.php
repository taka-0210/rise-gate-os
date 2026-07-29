<?php

namespace App\Services\Company;

use App\Models\CompanyLoan;
use Carbon\CarbonImmutable;

class RepaymentCapacityService
{
    public function __construct(private readonly LoanScheduleService $loanSchedule)
    {
    }

    public function annualPrincipalRepayment(int $organizationId, int $fiscalYear, int $closingMonth): int
    {
        return $this->annualPrincipalRepaymentDetails($organizationId, $fiscalYear, $closingMonth)['total'];
    }

    public function annualPrincipalRepaymentDetails(
        int $organizationId,
        int $fiscalYear,
        int $closingMonth,
        array $options = [],
    ): array
    {
        $startMonth = $closingMonth === 12 ? 1 : $closingMonth + 1;
        $start = CarbonImmutable::create($fiscalYear, $startMonth, 1, 0, 0, 0, 'Asia/Tokyo');
        $end = $start->addYear()->subDay()->endOfDay();
        $loans = CompanyLoan::query()
            ->where('organization_id', $organizationId)
            ->where('record_status', CompanyLoan::RECORD_CONFIRMED)
            ->with('balanceSnapshots')
            ->get();

        $executeExtraRepayments = (bool) ($options['execute_extra_repayments'] ?? true);
        $fundingOverrides = $options['extra_repayment_funding_overrides'] ?? [];

        $loans = $loans->map(function (CompanyLoan $loan) use (
            $start,
            $end,
            $executeExtraRepayments,
            $fundingOverrides,
        ): array {
            $schedule = collect($this->loanSchedule->build($loan->newCollection([$loan]), $start, $end));
            $amount = (int) $schedule->sum('principal_repaid');
            $monthlyPayment = $this->loanSchedule->effectiveMonthlyPayment($loan);
            $extraRepayment = (int) $schedule->sum(
                fn (array $row) => max(0, $row['principal_repaid'] - $monthlyPayment)
            );
            $funding = $fundingOverrides[$loan->id] ?? $loan->extra_repayment_funding;
            $isRefinanced = $extraRepayment > 0
                && $funding === CompanyLoan::EXTRA_REPAYMENT_REFINANCE;
            $scheduledRepayment = $amount - $extraRepayment;
            $includedExtraRepayment = $executeExtraRepayments && ! $isRefinanced
                ? $extraRepayment
                : 0;
            $includedAmount = $scheduledRepayment + $includedExtraRepayment;

            return [
                'id' => $loan->id,
                'management_number' => $loan->management_number,
                'financial_institution' => $loan->financial_institution,
                'amount' => $amount,
                'included_amount' => $includedAmount,
                'scheduled_repayment' => $scheduledRepayment,
                'extra_repayment' => $extraRepayment,
                'includes_extra_repayment' => $extraRepayment > 0,
                'extra_repayment_funding' => $funding,
                'is_refinanced' => $isRefinanced,
                'is_extra_repayment_executed' => $executeExtraRepayments,
            ];
        })
            ->filter(fn (array $loan) => $loan['amount'] > 0)
            ->sortByDesc('amount')
            ->values();

        return [
            'total' => (int) $loans->sum('included_amount'),
            'scheduled_total' => (int) $loans->sum('scheduled_repayment'),
            'extra_total' => $executeExtraRepayments
                ? (int) $loans->where('is_refinanced', false)->sum('extra_repayment')
                : 0,
            'refinanced_total' => (int) $loans->where('is_refinanced', true)->sum('extra_repayment'),
            'postponed_extra_total' => $executeExtraRepayments
                ? 0
                : (int) $loans->sum('extra_repayment'),
            'loans' => $loans,
        ];
    }
}
