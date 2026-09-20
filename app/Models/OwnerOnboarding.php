<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class OwnerOnboarding extends Model
{
    use HasFactory;

    public const STATUS_ISSUED = 'issued';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVOKED = 'revoked';

    public const DELIVERY_QUEUED = 'queued';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
        'public_id', 'issued_by_user_id', 'normalized_email', 'organization_name',
        'normalized_organization_name', 'duplicate_decision', 'distinct_company_reason',
        'pending_case_key',
        'status', 'token_hash', 'token_generation', 'expires_at', 'claimed_user_id',
        'completed_organization_id', 'completed_payload_hash', 'completed_at',
        'revoked_by_user_id', 'revoked_at', 'delivery_status', 'delivery_requested_at',
        'delivered_at', 'delivery_failed_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'token_generation' => 'integer',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'delivery_requested_at' => 'datetime',
            'delivered_at' => 'datetime',
            'delivery_failed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (OwnerOnboarding $onboarding): void {
            $onboarding->public_id ??= (string) Str::ulid();
        });
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function claimedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_user_id');
    }

    public function completedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'completed_organization_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(OwnerOnboardingOperation::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(OwnerOnboardingAuditEvent::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserLegalConsent::class);
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED && ! $this->revoked_at && ! $this->completed_at;
    }

    public function isExpired(): bool
    {
        return ! $this->expires_at || $this->expires_at->lessThanOrEqualTo(now());
    }
}
