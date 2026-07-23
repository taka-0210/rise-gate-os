<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFinancialPeriodRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_financial_period_id', 'organization_id', 'changed_by',
        'action', 'before_data', 'after_data',
    ];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array', 'created_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(CompanyFinancialPeriod::class, 'company_financial_period_id');
    }
}
