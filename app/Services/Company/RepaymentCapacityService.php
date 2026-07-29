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
        $end = CarbonImmutable::create($fiscalYear, $closingMonth, 1, 0, 0, 0, 'Asia/Tokyo')->endOfMonth();
        $start = $end->subYear()->addDay()->startOfMonth();
        $loans = CompanyLoan::query()
            ->where('organization_id', $organizationId)
            ->where('record_status', CompanyLoan::RECORD_CONFIRMED)
            ->with('balanceSnapshots')
            ->get();

        $loans = $loans->map(function (CompanyLoan $loan) use ($start, $end): array {
            $schedule = collect($this->loanSchedule->build($loan->newCollection([$loan]), $start, $end));
            $amount = (int) $schedule->sum('principal_repaid');
            $monthlyPayment = $this->loanSchedule->effectiveMonthlyPayment($loan);

            return [
                'management_number' => $loan->management_number,
                'financial_institution' => $loan->financial_institution,
                'amount' => $amount,
                'includes_extra_repayment' => $schedule->contains(
                    fn (array $row) => $row['principal_repaid'] > $monthlyPayment
                ),
            ];
        })
            ->filter(fn (array $loan) => $loan['amount'] > 0)
            ->sortByDesc('amount')
            ->values();

        return [
            'total' => (int) $loans->sum('amount'),
            'loans' => $loans,
        ];
    }
}
