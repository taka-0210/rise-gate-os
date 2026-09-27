<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiCommonAuditEvent extends Model
{
    protected $fillable = [
        'public_id', 'organization_id', 'ai_common_conversation_id', 'actor_user_id',
        'operation_id', 'event', 'subject_type', 'subject_public_id', 'result',
        'safe_error_code', 'metadata', 'event_fingerprint', 'occurred_at_utc',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at_utc' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $event) => $event->public_id ??= (string) Str::ulid());
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }
}
