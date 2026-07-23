<?php

namespace Tests\Unit;

use App\Services\Company\AnnualProfitLossCalculator;
use PHPUnit\Framework\TestCase;

class AnnualProfitLossCalculatorTest extends TestCase
{
    public function test_it_calculates_profit_and_ratios_from_source_values(): void
    {
        $result = (new AnnualProfitLossCalculator)->calculate([
            'period_number' => 21, 'fiscal_year' => 2024,
            'net_sales' => 1000, 'cost_of_sales' => 600,
            'selling_general_admin_expenses' => 300,
            'non_operating_income' => 20, 'non_operating_expenses' => 10,
            'extraordinary_income' => 5, 'extraordinary_losses' => 3, 'income_taxes' => 22,
        ]);

        $this->assertSame(400, $result['gross_profit']);
        $this->assertSame(100, $result['operating_profit']);
        $this->assertSame(110, $result['ordinary_profit']);
        $this->assertSame(112, $result['profit_before_tax']);
        $this->assertSame(90, $result['net_income']);
        $this->assertSame(0.4, $result['gross_profit_ratio']);
    }
}
