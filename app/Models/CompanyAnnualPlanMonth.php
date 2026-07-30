<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAnnualPlanMonth extends Model
{
    protected $fillable = [
        'company_annual_plan_id',
        'month',
        'plan_net_sales',
        'plan_cost_of_sales',
        'plan_selling_general_admin_expenses',
        'forecast_net_sales',
        'forecast_cost_of_sales',
        'forecast_selling_general_admin_expenses',
        'actual_net_sales',
        'actual_cost_of_sales',
        'actual_selling_general_admin_expenses',
    ];

    protected function casts(): array
    {
        return ['month' => 'date'];
    }

    public function annualPlan(): BelongsTo
    {
        return $this->belongsTo(CompanyAnnualPlan::class, 'company_annual_plan_id');
    }
}
