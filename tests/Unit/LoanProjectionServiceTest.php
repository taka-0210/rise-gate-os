<?php

namespace Tests\Unit;

use App\Services\Company\LoanProjectionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class LoanProjectionServiceTest extends TestCase
{
    public function test_it_reduces_amortizing_loans_and_keeps_overdraft_balance(): void
    {
        CarbonImmutable::setTestNow('2026-07-24');
        $loans = new Collection([
            (object) ['id' => 1, 'current_balance' => 1_000_000, 'monthly_principal_payment' => 100_000, 'loan_status' => 'active', 'maturity_on' => null],
            (object) ['id' => 2, 'current_balance' => 2_000_000, 'monthly_principal_payment' => 0, 'loan_status' => 'active', 'maturity_on' => null],
        ]);

        $result = (new LoanProjectionService)->project($loans, 2);

        $this->assertSame(3_000_000, $result[0]['balance']);
        $this->assertSame(2_900_000, $result[1]['balance']);
        $this->assertSame(2_800_000, $result[2]['balance']);
        CarbonImmutable::setTestNow();
    }
}
