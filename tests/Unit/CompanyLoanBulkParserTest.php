<?php

namespace Tests\Unit;

use App\Services\Company\CompanyLoanBulkParser;
use Carbon\Carbon;
use Tests\TestCase;

class CompanyLoanBulkParserTest extends TestCase
{
    public function test_it_defaults_balance_date_and_inherits_a_merged_bank_cell(): void
    {
        Carbon::setTestNow('2026-07-24 09:00:00');

        try {
            $text = implode("\n", [
                "A銀行\t1\t運転資金\t2025-05\t5年\t30000000\t23500000\t500000\t1.775\tvariable\t35013\t2030-04\t保証協付\t25\t2026-05-31\tactive",
                "\t2\t設備資金\t2025-09\t7年\t30000000\t26787000\t357000\t1.45\tfixed\t32775\t2032-08\t保証協付\t27\t\tactive",
            ]);

            $rows = app(CompanyLoanBulkParser::class)->parse($text);

            $this->assertSame('A銀行', $rows[1]['financial_institution']);
            $this->assertSame('2026-07-24', $rows[1]['balance_as_of']);
        } finally {
            Carbon::setTestNow();
        }
    }
}
