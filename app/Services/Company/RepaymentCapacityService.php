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
        $end = CarbonImmutable::create($fiscalYear, $closingMonth, 1, 0, 0, 0, 'Asia/Tokyo')->endOfMonth();
        $start = $end->subYear()->addDay()->startOfMonth();
        $loans = CompanyLoan::query()
            ->where('organization_id', $organizationId)
            ->where('record_status', CompanyLoan::RECORD_CONFIRMED)
            ->with('balanceSnapshots')
            ->get();

        return collect($this->loanSchedule->build($loans, $start, $end))
            ->sum('principal_repaid');
    }
}
