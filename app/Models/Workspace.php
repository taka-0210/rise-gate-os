<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Workspace extends Model
{
    use HasFactory, SoftDeletes;

    public const BILLING_INCLUDED = 'included';
    public const BILLING_ADDITIONAL = 'additional';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'public_id',
        'organization_id',
        'owner_user_id',
        'name',
        'slug',
        'billing_type',
        'status',
        'purpose',
    ];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            $workspace->public_id ??= (string) Str::ulid();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'owning_workspace_id');
    }

    public function improvements(): HasMany
    {
        return $this->hasMany(Improvement::class);
    }

    public function aiSetting(): HasOne
    {
        return $this->hasOne(WorkspaceAiSetting::class);
    }

    public function businessProfile(): HasOne
    {
        return $this->hasOne(WorkspaceBusinessProfile::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(WorkspaceBankAccount::class)->orderByDesc('is_default')->orderBy('sort_order');
    }
}
