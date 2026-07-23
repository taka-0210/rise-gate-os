<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Organization extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
    ];

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->public_id ??= (string) Str::ulid();
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot(['role', 'company_role', 'permissions', 'joined_at'])
            ->withTimestamps();
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function financialPeriods(): HasMany
    {
        return $this->hasMany(CompanyFinancialPeriod::class);
    }
}
