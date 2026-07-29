<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyDepreciationPeriod extends Model
{
    protected $fillable = [
        'organization_id',
        'fiscal_year',
        'depreciation_expense',
        'updated_by',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
