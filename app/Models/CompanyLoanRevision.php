<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyLoanRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'company_loan_id', 'organization_id', 'changed_by', 'action', 'before_data', 'after_data',
    ];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array', 'created_at' => 'datetime'];
    }
}
