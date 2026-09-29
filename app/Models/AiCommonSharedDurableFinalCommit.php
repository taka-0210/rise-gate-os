<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiCommonSharedDurableFinalCommit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['validated_source_start_sample' => 'integer', 'validated_source_end_sample' => 'integer', 'committed_at_utc' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $commit) => $commit->public_id ??= (string) Str::uuid());
    }
}
