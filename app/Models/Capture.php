<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Capture extends Model
{
    public const TYPE_SELF = 'self';

    public const TYPE_REQUEST = 'request';

    public const TYPE_TELL_LATER = 'tell_later';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CONVERTED = 'converted';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $capture) => $capture->public_id ??= (string) Str::ulid());
    }

    protected function casts(): array
    {
        return [
            'notify_at_utc' => 'datetime',
            'acknowledged_at_utc' => 'datetime',
            'closed_at_utc' => 'datetime',
            'cancelled_at_utc' => 'datetime',
            'converted_at_utc' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CaptureEvent::class);
    }

    public function actionRelation(): HasOne
    {
        return $this->hasOne(CaptureActionRelation::class);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
