<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AnnualManagementPolicyDepartment extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'annual_management_policy_id', 'organization_group_id', 'introduction', 'sort_order',
    ];

    protected function casts(): array { return ['sort_order' => 'integer']; }

    protected static function booted(): void
    {
        static::creating(fn (self $department) => $department->public_id ??= (string) Str::ulid());
    }

    public function annualPolicy(): BelongsTo { return $this->belongsTo(AnnualManagementPolicy::class); }
    public function group(): BelongsTo { return $this->belongsTo(OrganizationGroup::class, 'organization_group_id'); }
    public function statements(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyDepartmentStatement::class)->orderBy('sort_order')->orderBy('id');
    }
}
