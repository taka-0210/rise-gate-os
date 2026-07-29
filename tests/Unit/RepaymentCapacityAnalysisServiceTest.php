<?php

namespace Tests\Unit;

use App\Services\Company\RepaymentCapacityAnalysisService;
use PHPUnit\Framework\TestCase;

class RepaymentCapacityAnalysisServiceTest extends TestCase
{
    public function test_dscr_assessment_boundaries_are_explicit(): void
    {
        $service = new RepaymentCapacityAnalysisService();

        $this->assertSame('安全', $service->assessment(1.50)['label']);
        $this->assertSame('注意', $service->assessment(1.49)['label']);
        $this->assertSame('注意', $service->assessment(1.00)['label']);
        $this->assertSame('要改善', $service->assessment(0.99)['label']);
        $this->assertSame('未判定', $service->assessment(null)['label']);
    }

    public function test_analysis_returns_shortfall_causes_and_relevant_improvements(): void
    {
        $result = (new RepaymentCapacityAnalysisService())->analyze(
            netIncome: 1_000_000,
            depreciationExpense: 500_000,
            interestExpense: 100_000,
            principalRepayment: 4_000_000,
            selfFundedExtraRepayment: 2_000_000,
        );

        $this->assertSame(2_500_000, $result['shortfall']);
        $this->assertSame('要改善', $result['assessment']['label']);
        $this->assertContains('自己資金による一括返済が返済負担を押し上げています', $result['causes']);
        $this->assertContains('一括返済に借換えを利用できるか検討する', $result['improvements']);
    }
}
