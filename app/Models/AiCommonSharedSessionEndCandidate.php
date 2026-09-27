<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedSessionEndCandidate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'provenance' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Session-end candidates are immutable until an explicit Proposal is created.'));
        static::deleting(fn () => throw new LogicException('Session-end candidates are immutable.'));
    }
}
