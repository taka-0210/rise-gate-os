<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiCommonSharedSessionEndRun extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::ulid());
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(AiCommonSharedSessionEndCandidate::class, 'session_end_run_id');
    }
}
