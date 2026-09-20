<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BusinessDomainItem extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const KINDS = ['product', 'service', 'brand', 'location', 'channel', 'customer_segment', 'market', 'other'];

    protected $fillable = [
        'public_id', 'business_domain_id', 'kind', 'name', 'description', 'status', 'sort_order',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessDomainItem $item): void {
            $item->public_id ??= (string) Str::ulid();
        });
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(BusinessDomain::class, 'business_domain_id');
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(BusinessDomainItemAttribute::class)->orderBy('sort_order')->orderBy('id');
    }
}
