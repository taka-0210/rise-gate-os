<?php

namespace App\Services\Company;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LoanProjectionService
{
    public function project(Collection $loans, int $months = 60): array
    {
        $start = CarbonImmutable::now()->startOfMonth();
        $balances = $loans->mapWithKeys(fn ($loan) => [$loan->id => (int) $loan->current_balance])->all();
        $result = [];

        for ($offset = 0; $offset <= $months; $offset++) {
            $date = $start->addMonths($offset);
            if ($offset > 0) {
                foreach ($loans as $loan) {
                    $balance = $balances[$loan->id];
                    if ($balance <= 0 || $loan->loan_status !== 'active') continue;
                    if ($loan->maturity_on && $date->greaterThanOrEqualTo(CarbonImmutable::parse($loan->maturity_on)->startOfMonth())) {
                        $balances[$loan->id] = 0;
                    } elseif ($loan->monthly_principal_payment > 0) {
                        $balances[$loan->id] = max(0, $balance - (int) $loan->monthly_principal_payment);
                    }
                }
            }
            $result[] = ['date' => $date, 'balance' => array_sum($balances)];
        }

        return $result;
    }
}
