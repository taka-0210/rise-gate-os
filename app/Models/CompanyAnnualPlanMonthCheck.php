<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyAnnualPlanMonthCheck extends Model
{
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'company_annual_plan_id',
        'month',
        'metric',
        'status',
        'accounting_amount',
        'note',
        'resolved_at',
        'resolved_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'resolved_at' => 'datetime',
        ];
    }

    public function annualPlan(): BelongsTo
    {
        return $this->belongsTo(CompanyAnnualPlan::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
