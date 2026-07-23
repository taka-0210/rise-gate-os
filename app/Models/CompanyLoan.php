<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyLoan extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PLANNED = 'planned';
    public const RECORD_DRAFT = 'draft';
    public const RECORD_CONFIRMED = 'confirmed';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_BULK = 'bulk';

    protected $fillable = [
        'organization_id', 'financial_institution', 'management_number', 'purpose',
        'executed_on', 'term_label', 'original_amount', 'current_balance',
        'monthly_principal_payment', 'annual_interest_rate', 'interest_type',
        'recent_interest_amount', 'maturity_on', 'guarantee_type', 'repayment_day',
        'balance_as_of', 'loan_status', 'record_status', 'source_type', 'notes',
        'confirmed_at', 'confirmed_by',
    ];

    protected function casts(): array
    {
        return [
            'executed_on' => 'date', 'maturity_on' => 'date', 'balance_as_of' => 'date',
            'annual_interest_rate' => 'decimal:4', 'confirmed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(CompanyLoanRevision::class);
    }

    public function balanceSnapshots(): HasMany
    {
        return $this->hasMany(CompanyLoanBalanceSnapshot::class);
    }
}
