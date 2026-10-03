<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AnnualManagementPolicyRelation extends Model
{
    use HasFactory;

    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'public_id', 'organization_id', 'annual_management_policy_id', 'source_type', 'source_public_id',
        'target_type', 'target_public_id', 'current_version', 'current_status',
    ];

    protected function casts(): array { return ['current_version' => 'integer']; }

    protected static function booted(): void
    {
        static::creating(fn (self $relation) => $relation->public_id ??= (string) Str::ulid());
    }

    public function annualPolicy(): BelongsTo { return $this->belongsTo(AnnualManagementPolicy::class); }
    public function versions(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyRelationVersion::class)->orderByDesc('version_no');
    }
}
