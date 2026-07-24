<?php

namespace App\Services\Company;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LoanScheduleService
{
    public function build(Collection $loans, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $loans->loadMissing('balanceSnapshots');
        $rows = [];

        for ($month = $start->startOfMonth(); $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            $cells = [];
            $total = 0;

            foreach ($loans as $loan) {
                $cell = $this->balanceForMonth($loan, $month);
                $cells[$loan->id] = $cell;
                $total += $cell['balance'] ?? 0;
            }

            $rows[] = ['month' => $month, 'cells' => $cells, 'total' => $total];
        }

        return $rows;
    }

    private function balanceForMonth(object $loan, CarbonImmutable $month): array
    {
        $executionMonth = $loan->executed_on ? CarbonImmutable::parse($loan->executed_on)->startOfMonth() : null;
        if ($executionMonth && $month->lessThan($executionMonth)) {
            return ['balance' => null, 'actual' => false];
        }
        $completedMonth = $loan->completed_on ? CarbonImmutable::parse($loan->completed_on)->startOfMonth() : null;
        if ($completedMonth && $month->greaterThanOrEqualTo($completedMonth)) {
            return ['balance' => 0, 'actual' => false];
        }

        $snapshots = $loan->balanceSnapshots
            ->sortBy('balance_as_of')
            ->values();
        $exact = $snapshots->last(fn ($snapshot) => $snapshot->balance_as_of->format('Y-m') === $month->format('Y-m'));
        if ($exact) {
            return ['balance' => (int) $exact->balance, 'actual' => true];
        }

        $before = $snapshots->last(fn ($snapshot) => $snapshot->balance_as_of->startOfMonth()->lessThan($month));
        $after = $snapshots->first(fn ($snapshot) => $snapshot->balance_as_of->startOfMonth()->greaterThan($month));
        $payment = max(0, (int) $loan->monthly_principal_payment);
        $mode = $loan->balance_projection_mode
            ?: ($payment === 0 ? 'hold' : 'amortizing');
        $projectedPayment = in_array($mode, ['hold', 'bullet', 'revolving'], true) ? 0 : $payment;

        if ($before) {
            $distance = $before->balance_as_of->startOfMonth()->diffInMonths($month);
            $balance = (int) $before->balance - ($projectedPayment * $distance);
        } elseif ($after) {
            $distance = $month->diffInMonths($after->balance_as_of->startOfMonth());
            $balance = (int) $after->balance + ($projectedPayment * $distance);
        } else {
            $anchor = $loan->balance_as_of
                ? CarbonImmutable::parse($loan->balance_as_of)->startOfMonth()
                : CarbonImmutable::now()->startOfMonth();
            $distance = $anchor->diffInMonths($month, false);
            $balance = (int) $loan->current_balance - ($projectedPayment * $distance);
        }

        $balance = min((int) $loan->original_amount, max(0, $balance));
        $maturityMonth = $loan->maturity_on ? CarbonImmutable::parse($loan->maturity_on)->startOfMonth() : null;
        if ($maturityMonth && $month->greaterThanOrEqualTo($maturityMonth) && in_array($mode, ['amortizing', 'bullet'], true)) {
            $balance = 0;
        }

        return ['balance' => $balance, 'actual' => false];
    }
}
