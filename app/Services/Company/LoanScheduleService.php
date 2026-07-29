<?php

namespace App\Services\Company;

use App\Models\CompanyLoan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LoanScheduleService
{
    public function effectiveMonthlyPayment(object $loan): int
    {
        $registered = max(0, (int) $loan->monthly_principal_payment);
        if ($registered > 0) {
            return $registered;
        }
        if (($loan->balance_projection_mode ?? 'amortizing') !== 'amortizing' || ! $loan->completed_on) {
            return 0;
        }

        $months = 0;
        if ($loan->executed_on) {
            $executed = CarbonImmutable::parse($loan->executed_on)->startOfMonth();
            $completed = CarbonImmutable::parse($loan->completed_on)->startOfMonth();
            $months = (int) $executed->diffInMonths($completed);
        }
        if ($months <= 0 && preg_match('/([\d.]+)\s*年/u', (string) $loan->term_label, $matches)) {
            $months = (int) round(((float) $matches[1]) * 12);
        } elseif ($months <= 0 && preg_match('/(\d+)\s*(?:か月|ヶ月|ケ月|月)/u', (string) $loan->term_label, $matches)) {
            $months = (int) $matches[1];
        }

        return $months > 0 ? (int) ceil(((int) $loan->original_amount) / $months) : 0;
    }

    public function build(Collection $loans, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $loans->loadMissing('balanceSnapshots');
        $rows = [];
        $previousCells = $loans->mapWithKeys(
            fn ($loan) => [$loan->id => $this->balanceForMonth($loan, $start->subMonth())]
        )->all();

        for ($month = $start->startOfMonth(); $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            $cells = [];
            $total = 0;
            $principalRepaid = 0;

            foreach ($loans as $loan) {
                $cell = $this->balanceForMonth($loan, $month);
                $cell['payment_number'] = $this->paymentNumberForMonth($loan, $month);
                $cells[$loan->id] = $cell;
                $total += $cell['balance'] ?? 0;

                $previousBalance = $previousCells[$loan->id]['balance'] ?? null;
                if ($previousBalance !== null && $cell['balance'] !== null) {
                    $principalRepaid += max(0, $previousBalance - $cell['balance']);
                } elseif (
                    $cell['balance'] !== null
                    && $loan->executed_on
                    && CarbonImmutable::parse($loan->executed_on)->isSameMonth($month)
                ) {
                    $principalRepaid += max(0, (int) $loan->original_amount - $cell['balance']);
                }
                $previousCells[$loan->id] = $cell;
            }

            $rows[] = [
                'month' => $month,
                'cells' => $cells,
                'total' => $total,
                'principal_repaid' => $principalRepaid,
            ];
        }

        return $rows;
    }

    private function paymentNumberForMonth(object $loan, CarbonImmutable $month): ?int
    {
        if (! $loan->first_payment_on || ! $loan->scheduled_payment_count) {
            return null;
        }

        $firstPaymentMonth = CarbonImmutable::parse($loan->first_payment_on)->startOfMonth();
        if ($month->lessThan($firstPaymentMonth)) {
            return null;
        }
        if ($loan->completed_on && $month->greaterThan(CarbonImmutable::parse($loan->completed_on)->startOfMonth())) {
            return null;
        }

        $number = (int) $firstPaymentMonth->diffInMonths($month) + 1;

        return $number <= (int) $loan->scheduled_payment_count ? $number : null;
    }

    private function balanceForMonth(object $loan, CarbonImmutable $month): array
    {
        $executionMonth = $loan->executed_on ? CarbonImmutable::parse($loan->executed_on)->startOfMonth() : null;
        if ($executionMonth && $month->lessThan($executionMonth)) {
            return ['balance' => null, 'actual' => false];
        }
        $completedMonth = $loan->completed_on ? CarbonImmutable::parse($loan->completed_on)->startOfMonth() : null;
        $zeroMonth = $completedMonth?->addMonth();
        if ($zeroMonth && $month->greaterThanOrEqualTo($zeroMonth)) {
            return ['balance' => 0, 'actual' => false];
        }

        $snapshots = $loan->balanceSnapshots
            ->sortBy('balance_as_of')
            ->values();
        $exact = $snapshots->last(fn ($snapshot) => $snapshot->balance_as_of->format('Y-m') === $month->format('Y-m'));
        if ($exact) {
            return ['balance' => (int) $exact->balance, 'actual' => true];
        }

        $payment = $this->effectiveMonthlyPayment($loan);
        $mode = $loan->balance_projection_mode
            ?: ($payment === 0 ? 'hold' : 'amortizing');
        $projectedPayment = in_array($mode, ['hold', 'bullet', 'revolving'], true) ? 0 : $payment;
        $before = $snapshots->last(fn ($snapshot) => $snapshot->balance_as_of->startOfMonth()->lessThan($month));
        $after = $snapshots->first(function ($snapshot) use ($month, $zeroMonth): bool {
            $snapshotMonth = $snapshot->balance_as_of->startOfMonth();

            return $snapshotMonth->greaterThan($month)
                && (! $zeroMonth || $snapshotMonth->lessThan($zeroMonth));
        });

        if ($before) {
            $distance = $before->balance_as_of->startOfMonth()->diffInMonths($month);
            $balance = (int) $before->balance - ($projectedPayment * $distance);
        } elseif ($after) {
            $distance = $month->diffInMonths($after->balance_as_of->startOfMonth());
            $balance = (int) $after->balance + ($projectedPayment * $distance);
        } elseif ($completedMonth && $executionMonth) {
            $distance = $executionMonth->diffInMonths($month);
            $balance = match ($mode) {
                'amortizing' => (int) $loan->original_amount - ($projectedPayment * $distance),
                default => (int) $loan->original_amount,
            };
        } elseif ($zeroMonth) {
            $distance = $month->diffInMonths($zeroMonth);
            $balance = match ($mode) {
                'amortizing' => $projectedPayment * $distance,
                default => (int) $loan->original_amount,
            };
        } else {
            $anchor = $loan->balance_as_of
                ? CarbonImmutable::parse($loan->balance_as_of)->startOfMonth()
                : CarbonImmutable::now()->startOfMonth();
            $distance = $anchor->diffInMonths($month, false);
            $balance = (int) $loan->current_balance - ($projectedPayment * $distance);
        }

        $balance = min((int) $loan->original_amount, max(0, $balance));
        $maturityMonth = $loan->maturity_on ? CarbonImmutable::parse($loan->maturity_on)->startOfMonth() : null;
        if (! $completedMonth && $maturityMonth && $month->greaterThanOrEqualTo($maturityMonth) && in_array($mode, ['amortizing', 'bullet'], true)) {
            $balance = 0;
        }

        return ['balance' => $balance, 'actual' => false];
    }
}
