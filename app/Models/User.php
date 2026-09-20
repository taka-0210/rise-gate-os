<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'is_system_admin' => false,
        'is_active' => true,
        'credential_generation' => 1,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_system_admin',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'credential_generation',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_system_admin' => 'boolean',
            'is_active' => 'boolean',
            'credential_generation' => 'integer',
            'avatar_width' => 'integer',
            'avatar_height' => 'integer',
            'avatar_updated_at' => 'datetime',
        ];
    }

    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->withPivot([
                'role', 'organization_role', 'position', 'membership_status',
                'company_role', 'permissions', 'joined_at',
            ])
            ->withTimestamps();
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function ownedWorkspaces(): HasMany
    {
        return $this->hasMany(Workspace::class, 'owner_user_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_to');
    }

    public function projectLocalConnections(): HasMany
    {
        return $this->hasMany(ProjectLocalConnection::class);
    }

    public function accountEmailRequests(): HasMany
    {
        return $this->hasMany(AccountEmailRequest::class);
    }

    public function organizationInvitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class, 'claimed_user_id');
    }

    public function canAccessWorkspace(int $workspaceId): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->workspaces()
            ->where('workspaces.id', $workspaceId)
            ->where('workspaces.status', Workspace::STATUS_ACTIVE)
            ->whereExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('organization_users')
                    ->whereColumn('organization_users.organization_id', 'workspaces.organization_id')
                    ->where('organization_users.user_id', $this->id)
                    ->where('organization_users.membership_status', OrganizationUser::STATUS_ACTIVE);
            })
            ->exists();
    }
}
