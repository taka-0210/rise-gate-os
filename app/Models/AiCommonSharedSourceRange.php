<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiCommonSharedSourceRange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'frame_sequence' => 'integer', 'start_sample' => 'integer', 'end_sample' => 'integer', 'sample_rate' => 'integer', 'bit_depth' => 'integer', 'channels' => 'integer', 'received_at_utc' => 'immutable_datetime', 'accepted_at_utc' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $range) => $range->public_id ??= (string) Str::uuid());
    }
}
