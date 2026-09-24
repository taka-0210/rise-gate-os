<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrganizationGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'organization_id',
        'name',
        'archived_at',
        'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationGroup $group): void {
            $group->public_id ??= (string) Str::ulid();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationGroupMembership::class);
    }

    public function projectAudiences(): HasMany
    {
        return $this->hasMany(ProjectGroupAudience::class);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function plannedInvitations(): BelongsToMany
    {
        return $this->belongsToMany(
            OrganizationInvitation::class,
            'organization_invitation_groups',
        )->withTimestamps();
    }
}
