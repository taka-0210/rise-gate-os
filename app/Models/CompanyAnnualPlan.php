<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyAnnualPlan extends Model
{
    protected $fillable = [
        'organization_id',
        'fiscal_year',
        'period_number',
        'plan_net_sales',
        'plan_gross_profit',
        'plan_selling_general_admin_expenses',
        'plan_net_income',
        'plan_interest_expense',
        'plan_depreciation_expense',
        'forecast_net_sales',
        'forecast_gross_profit',
        'forecast_selling_general_admin_expenses',
        'forecast_net_income',
        'forecast_interest_expense',
        'forecast_depreciation_expense',
        'updated_by',
    ];
}
