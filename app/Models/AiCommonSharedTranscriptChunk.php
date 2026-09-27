<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedTranscriptChunk extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'ordinal' => 'integer', 'range_start_ms' => 'integer', 'range_end_ms' => 'integer', 'character_count' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Transcript chunks are immutable; create a new checkpoint after revision.'));
        static::deleting(fn () => throw new LogicException('Transcript chunks are immutable.'));
    }
}
