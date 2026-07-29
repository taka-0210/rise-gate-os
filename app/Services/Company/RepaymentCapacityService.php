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

    public function annualPrincipalRepaymentDetails(int $organizationId, int $fiscalYear, int $closingMonth): array
    {
        $startMonth = $closingMonth === 12 ? 1 : $closingMonth + 1;
        $start = CarbonImmutable::create($fiscalYear, $startMonth, 1, 0, 0, 0, 'Asia/Tokyo');
        $end = $start->addYear()->subDay()->endOfDay();
        $loans = CompanyLoan::query()
            ->where('organization_id', $organizationId)
            ->where('record_status', CompanyLoan::RECORD_CONFIRMED)
            ->with('balanceSnapshots')
            ->get();

        $loans = $loans->map(function (CompanyLoan $loan) use ($start, $end): array {
            $schedule = collect($this->loanSchedule->build($loan->newCollection([$loan]), $start, $end));
            $amount = (int) $schedule->sum('principal_repaid');
            $monthlyPayment = $this->loanSchedule->effectiveMonthlyPayment($loan);
            $extraRepayment = (int) $schedule->sum(
                fn (array $row) => max(0, $row['principal_repaid'] - $monthlyPayment)
            );

            return [
                'management_number' => $loan->management_number,
                'financial_institution' => $loan->financial_institution,
                'amount' => $amount,
                'scheduled_repayment' => $amount - $extraRepayment,
                'extra_repayment' => $extraRepayment,
                'includes_extra_repayment' => $extraRepayment > 0,
            ];
        })
            ->filter(fn (array $loan) => $loan['amount'] > 0)
            ->sortByDesc('amount')
            ->values();

        return [
            'total' => (int) $loans->sum('amount'),
            'scheduled_total' => (int) $loans->sum('scheduled_repayment'),
            'extra_total' => (int) $loans->sum('extra_repayment'),
            'loans' => $loans,
        ];
    }
}
