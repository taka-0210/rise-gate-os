<?php

namespace App\Services\Company;

class AnnualProfitLossCalculator
{
    public const INPUT_FIELDS = [
        'period_number', 'fiscal_year', 'net_sales', 'cost_of_sales',
        'selling_general_admin_expenses', 'non_operating_income',
        'non_operating_expenses', 'extraordinary_income',
        'extraordinary_losses', 'income_taxes',
    ];

    public function calculate(array $input): array
    {
        $data = [];
        foreach (self::INPUT_FIELDS as $field) {
            $data[$field] = (int) ($input[$field] ?? 0);
        }

        $sales = $data['net_sales'];
        $data['cost_ratio'] = $this->ratio($data['cost_of_sales'], $sales);
        $data['gross_profit'] = $sales - $data['cost_of_sales'];
        $data['gross_profit_ratio'] = $this->ratio($data['gross_profit'], $sales);
        $data['sga_ratio'] = $this->ratio($data['selling_general_admin_expenses'], $sales);
        $data['operating_profit'] = $data['gross_profit'] - $data['selling_general_admin_expenses'];
        $data['operating_profit_ratio'] = $this->ratio($data['operating_profit'], $sales);
        $data['ordinary_profit'] = $data['operating_profit'] + $data['non_operating_income'] - $data['non_operating_expenses'];
        $data['profit_before_tax'] = $data['ordinary_profit'] + $data['extraordinary_income'] - $data['extraordinary_losses'];
        $data['net_income'] = $data['profit_before_tax'] - $data['income_taxes'];

        return $data;
    }

    private function ratio(int $amount, int $sales): ?float
    {
        return $sales === 0 ? null : round($amount / $sales, 6);
    }
}
