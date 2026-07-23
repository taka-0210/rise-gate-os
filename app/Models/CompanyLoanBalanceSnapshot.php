<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyLoanBalanceSnapshot extends Model
{
    protected $fillable = [
        'company_loan_id', 'organization_id', 'balance_as_of', 'balance',
        'monthly_principal_payment', 'interest_amount', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['balance_as_of' => 'date'];
    }
}
