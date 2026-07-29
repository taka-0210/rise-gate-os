<?php

namespace App\Services\Company;

class RepaymentCapacityAnalysisService
{
    public function analyze(
        ?int $netIncome,
        ?int $depreciationExpense,
        ?int $interestExpense,
        int $principalRepayment,
        int $selfFundedExtraRepayment = 0,
        int $refinancedExtraRepayment = 0,
        int $newLoanPrincipalRepayment = 0,
    ): array {
        $repaymentSource = $netIncome !== null && $depreciationExpense !== null
            ? $netIncome + $depreciationExpense + ($interestExpense ?? 0)
            : null;
        $usesFullDscr = $interestExpense !== null;
        $annualDebtService = $usesFullDscr
            ? $principalRepayment + $interestExpense
            : $principalRepayment;
        $remainingCapacity = $repaymentSource !== null
            ? $repaymentSource - $annualDebtService
            : null;
        $coverageRatio = $repaymentSource !== null && $annualDebtService > 0
            ? $repaymentSource / $annualDebtService
            : null;
        $assessment = $this->assessment($coverageRatio);

        return [
            'repayment_source' => $repaymentSource,
            'annual_debt_service' => $annualDebtService,
            'remaining_capacity' => $remainingCapacity,
            'shortfall' => $remainingCapacity !== null ? max(0, -$remainingCapacity) : null,
            'surplus' => $remainingCapacity !== null ? max(0, $remainingCapacity) : null,
            'coverage_ratio' => $coverageRatio,
            'assessment' => $assessment,
            'dscr_type' => $usesFullDscr ? 'DSCR' : '簡易DSCR',
            'causes' => $this->causes(
                $assessment['key'],
                $netIncome,
                $principalRepayment,
                $selfFundedExtraRepayment,
                $newLoanPrincipalRepayment,
            ),
            'improvements' => $this->improvements(
                $assessment['key'],
                $netIncome,
                $selfFundedExtraRepayment,
                $newLoanPrincipalRepayment,
            ),
            'refinanced_extra_repayment' => $refinancedExtraRepayment,
        ];
    }

    public function assessment(?float $coverageRatio): array
    {
        if ($coverageRatio === null) {
            return ['key' => 'unavailable', 'label' => '未判定'];
        }
        if ($coverageRatio >= 1.5) {
            return ['key' => 'safe', 'label' => '安全'];
        }
        if ($coverageRatio >= 1.0) {
            return ['key' => 'caution', 'label' => '注意'];
        }

        return ['key' => 'improvement', 'label' => '要改善'];
    }

    private function causes(
        string $assessment,
        ?int $netIncome,
        int $principalRepayment,
        int $selfFundedExtraRepayment,
        int $newLoanPrincipalRepayment,
    ): array {
        if (! in_array($assessment, ['caution', 'improvement'], true)) {
            return [];
        }

        $causes = [];
        if ($netIncome !== null && $netIncome <= 0) {
            $causes[] = '当期純利益が確保できていません';
        } elseif ($netIncome !== null && $netIncome < $principalRepayment) {
            $causes[] = '利益が元本返済額に対して不足しています';
        }
        if ($selfFundedExtraRepayment > 0) {
            $causes[] = '自己資金による一括返済が返済負担を押し上げています';
        }
        if ($newLoanPrincipalRepayment > 0) {
            $causes[] = '新規借入の返済が今期から発生します';
        }
        if ($causes === []) {
            $causes[] = '返済原資に対して年間の返済負担が大きくなっています';
        }

        return $causes;
    }

    private function improvements(
        string $assessment,
        ?int $netIncome,
        int $selfFundedExtraRepayment,
        int $newLoanPrincipalRepayment,
    ): array {
        if (! in_array($assessment, ['caution', 'improvement'], true)) {
            return [];
        }

        $items = [];
        if ($netIncome !== null) {
            $items[] = '売上・利益計画を見直し、返済原資を増やせるか検討する';
        }
        $items[] = '金融機関と返済条件の見直しを検討する';
        if ($selfFundedExtraRepayment > 0) {
            $items[] = '一括返済に借換えを利用できるか検討する';
        }
        if ($newLoanPrincipalRepayment > 0) {
            $items[] = '新規借入の実行時期や返済期間を調整できるか検討する';
        }

        return $items;
    }
}
