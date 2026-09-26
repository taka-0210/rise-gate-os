<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CompanyNotification extends Model
{
    public const TYPE_ACTION_ASSIGNED = 'action_assigned';
    public const TYPE_REVIEW_ATTENTION = 'review_attention';
    public const TYPE_ACTION_RETURNED = 'action_returned';
    public const TYPE_TODAY_DIGEST = 'today_digest';
    public const TYPE_CAPTURE_REMINDER = 'capture_reminder';
    public const TYPE_CAPTURE_REQUEST = 'capture_request';
    public const TYPE_CAPTURE_TELL_LATER = 'capture_tell_later';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'content_visible_at_utc' => 'datetime', 'eligible_at_utc' => 'datetime', 'read_at_utc' => 'datetime',
            'source_seen_at_utc' => 'datetime', 'action_done_at_utc' => 'datetime', 'cancelled_at_utc' => 'datetime',
            'membership_access_epoch' => 'integer', 'credential_generation' => 'integer',
        ];
    }

    public function recipient(): BelongsTo { return $this->belongsTo(User::class, 'recipient_user_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function deliveries(): HasMany { return $this->hasMany(NotificationDelivery::class); }
    public function getRouteKeyName(): string { return 'public_id'; }
}
