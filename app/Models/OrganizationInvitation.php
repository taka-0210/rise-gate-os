<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OrganizationInvitation extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REVOKED = 'revoked';

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_QUEUED = 'queued';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
        'public_id',
        'organization_id',
        'created_by_user_id',
        'sponsor_user_id',
        'normalized_email',
        'intended_organization_role',
        'status',
        'pending_email_key',
        'token_hash',
        'token_generation',
        'expires_at',
        'organization_user_id',
        'claimed_user_id',
        'accepted_by_user_id',
        'accepted_at',
        'revoked_by_user_id',
        'revoked_at',
        'delivery_status',
        'delivery_requested_at',
        'delivered_at',
        'delivery_failed_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'token_generation' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'delivery_requested_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OrganizationInvitation $invitation): void {
            $invitation->public_id ??= (string) Str::ulid();
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsor_user_id');
    }

    public function claimedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_user_id');
    }

    public function organizationMembership(): BelongsTo
    {
        return $this->belongsTo(OrganizationUser::class, 'organization_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            OrganizationGroup::class,
            'organization_invitation_groups',
        )->withTimestamps();
    }

    public function operations(): HasMany
    {
        return $this->hasMany(OrganizationInvitationOperation::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ! $this->revoked_at
            && ! $this->accepted_at;
    }

    public function isExpired(): bool
    {
        return ! $this->expires_at || $this->expires_at->lessThanOrEqualTo(now());
    }
}
