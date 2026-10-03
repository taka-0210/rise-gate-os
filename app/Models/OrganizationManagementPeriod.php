<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class OrganizationManagementPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'organization_id', 'name', 'starts_on', 'ends_on', 'version',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $period) => $period->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(OrganizationManagementPeriodVersion::class)->orderByDesc('version_no');
    }

    public function annualPolicy(): HasOne
    {
        return $this->hasOne(AnnualManagementPolicy::class);
    }
}
