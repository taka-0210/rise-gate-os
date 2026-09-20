<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'fiscal_year_end_month',
        'standard_workspace_id',
        'personal_workspace_creation_enabled',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year_end_month' => 'integer',
            'personal_workspace_creation_enabled' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->public_id ??= (string) Str::ulid();
        });
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_users')
            ->withPivot([
                'role', 'organization_role', 'position', 'membership_status',
                'access_epoch', 'lifecycle_version', 'status_changed_at',
                'status_changed_by_user_id', 'status_change_reason',
                'company_role', 'permissions', 'joined_at',
            ])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(OrganizationGroup::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(OrganizationAuditEvent::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function standardWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'standard_workspace_id');
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    public function financialPeriods(): HasMany
    {
        return $this->hasMany(CompanyFinancialPeriod::class);
    }

    public function depreciationPeriods(): HasMany
    {
        return $this->hasMany(CompanyDepreciationPeriod::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(CompanyLoan::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(CompanyObservation::class);
    }

    public function senses(): HasMany
    {
        return $this->hasMany(CompanySense::class);
    }

    public function companyImprovements(): HasMany
    {
        return $this->hasMany(CompanyImprovement::class);
    }
}
