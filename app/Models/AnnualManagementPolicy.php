<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AnnualManagementPolicy extends Model
{
    use HasFactory;

    public const VIEW_SCOPE_EXPLICIT = 'explicit';
    public const VIEW_SCOPE_ALL_ACTIVE_STAFF = 'all_active_staff';
    public const VIEW_SCOPES = [self::VIEW_SCOPE_EXPLICIT, self::VIEW_SCOPE_ALL_ACTIVE_STAFF];

    protected $fillable = [
        'public_id', 'organization_id', 'organization_management_period_id', 'draft_version',
        'base_approved_revision_no', 'current_approved_revision_id', 'draft_period_name',
        'draft_starts_on', 'draft_ends_on', 'draft_purpose', 'draft_background', 'draft_policy',
        'approved_view_scope', 'relation_version', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'draft_version' => 'integer', 'base_approved_revision_no' => 'integer',
            'relation_version' => 'integer', 'draft_starts_on' => 'date', 'draft_ends_on' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $policy) => $policy->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(OrganizationManagementPeriod::class, 'organization_management_period_id');
    }

    public function themes(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyTheme::class)->orderBy('sort_order')->orderBy('id');
    }

    public function priorities(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyPriority::class)->orderBy('sort_order')->orderBy('id');
    }

    public function departments(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyDepartment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyGrant::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyRevision::class)->orderByDesc('revision_no');
    }

    public function currentApprovedRevision(): BelongsTo
    {
        return $this->belongsTo(AnnualManagementPolicyRevision::class, 'current_approved_revision_id');
    }

    public function relations(): HasMany
    {
        return $this->hasMany(AnnualManagementPolicyRelation::class);
    }
}
