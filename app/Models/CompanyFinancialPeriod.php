<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyFinancialPeriod extends Model
{
    public const STATUS_ACTUAL = 'actual';
    public const STATUS_PLAN = 'plan';
    public const STATUS_FORECAST = 'forecast';
    public const STATUS_UNCONFIRMED = 'unconfirmed';

    protected $fillable = [
        'organization_id',
        'period_number',
        'fiscal_year',
        'status',
        'net_sales',
        'cost_of_sales',
        'cost_ratio',
        'gross_profit',
        'gross_profit_ratio',
        'selling_general_admin_expenses',
        'sga_ratio',
        'operating_profit',
        'operating_profit_ratio',
        'non_operating_income',
        'non_operating_expenses',
        'ordinary_profit',
        'extraordinary_income',
        'extraordinary_losses',
        'profit_before_tax',
        'income_taxes',
        'net_income',
        'source_filename',
        'source_hash',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'cost_ratio' => 'decimal:6',
            'gross_profit_ratio' => 'decimal:6',
            'sga_ratio' => 'decimal:6',
            'operating_profit_ratio' => 'decimal:6',
            'imported_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
