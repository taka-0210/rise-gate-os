<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiCommonSharedProviderSession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'receive_order' => 'integer', 'opened_at_utc' => 'immutable_datetime', 'closing_at_utc' => 'immutable_datetime', 'closed_at_utc' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $session) => $session->public_id ??= (string) Str::uuid());
    }
}
