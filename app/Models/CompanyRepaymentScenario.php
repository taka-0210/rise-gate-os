<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyRepaymentScenario extends Model
{
    protected $fillable = [
        'organization_id',
        'fiscal_year',
        'net_sales',
        'net_income',
        'depreciation_expense',
        'interest_expense',
        'execute_extra_repayments',
        'extra_repayment_funding_overrides',
        'new_loans',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'execute_extra_repayments' => 'boolean',
            'extra_repayment_funding_overrides' => 'array',
            'new_loans' => 'array',
        ];
    }
}
