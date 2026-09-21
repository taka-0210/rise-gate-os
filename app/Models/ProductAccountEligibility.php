<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductAccountEligibility extends Model
{
    public const MODE_UNSTARTED = 'unstarted';

    public const MODE_SINGLE = 'single';

    public const MODE_LEGACY_MULTI = 'legacy_multi';

    public const MODE_REVIEW_REQUIRED = 'review_required';

    protected $fillable = [
        'user_id',
        'mode',
        'product_organization_id',
        'classification_version',
        'classified_at',
        'evidence_ref',
    ];

    protected function casts(): array
    {
        return ['classified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function productOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'product_organization_id');
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(ProductOrganizationCompatibility::class);
    }
}
