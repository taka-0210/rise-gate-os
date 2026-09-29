<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiCommonSharedRelayLease extends Model
{
    public const STATE_ACTIVE = 'active';

    public const STATE_REVOKING = 'revoking';

    public const STATE_CLOSED = 'closed';

    public const STATE_EXPIRED = 'expired';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'lease_version' => 'integer', 'issued_at_utc' => 'immutable_datetime', 'expires_at_utc' => 'immutable_datetime', 'refreshed_at_utc' => 'immutable_datetime', 'revoked_at_utc' => 'immutable_datetime', 'closed_at_utc' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $lease) => $lease->public_id ??= (string) Str::uuid());
    }
}
