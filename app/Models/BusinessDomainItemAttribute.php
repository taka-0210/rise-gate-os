<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BusinessDomainItemAttribute extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const AXES = ['what', 'who', 'value', 'where', 'position'];

    protected $fillable = [
        'public_id', 'business_domain_item_id', 'axis', 'label', 'value_text', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessDomainItemAttribute $attribute): void {
            $attribute->public_id ??= (string) Str::ulid();
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(BusinessDomainItem::class, 'business_domain_item_id');
    }
}
