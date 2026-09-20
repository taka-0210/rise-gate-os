<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BusinessDomain extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    public const SUMMARY_FIELDS = [
        'description', 'what_summary', 'who_summary', 'value_proposition',
        'geographic_scope_summary', 'market_position_summary', 'self_recognized_strengths',
    ];

    protected $fillable = [
        'public_id', 'organization_id', 'name', 'description', 'what_summary', 'who_summary',
        'value_proposition', 'geographic_scope_summary', 'market_position_summary',
        'self_recognized_strengths', 'status', 'version', 'created_by_user_id',
        'updated_by_user_id', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['version' => 'integer', 'archived_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessDomain $domain): void {
            $domain->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BusinessDomainItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(BusinessDomainRevision::class)->orderByDesc('revision_no');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(BusinessDomainOperation::class);
    }
}
