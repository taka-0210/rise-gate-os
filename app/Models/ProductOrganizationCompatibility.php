<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductOrganizationCompatibility extends Model
{
    protected $fillable = [
        'product_account_eligibility_id',
        'organization_user_id',
        'cutoff_at',
        'evidence_ref',
    ];

    protected function casts(): array
    {
        return ['cutoff_at' => 'datetime'];
    }

    public function eligibility(): BelongsTo
    {
        return $this->belongsTo(ProductAccountEligibility::class, 'product_account_eligibility_id');
    }

    public function organizationMembership(): BelongsTo
    {
        return $this->belongsTo(OrganizationUser::class, 'organization_user_id');
    }
}
