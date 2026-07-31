<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function months(): HasMany
    {
        return $this->hasMany(CompanyAnnualPlanMonth::class)->orderBy('month');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(CompanyAnnualPlanMonthCheck::class)->orderBy('month');
    }
}
