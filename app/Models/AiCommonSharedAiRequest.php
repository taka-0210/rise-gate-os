<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiCommonSharedAiRequest extends Model
{
    public const STATE_PROCESSING = 'processing';

    public const STATE_ANSWER_READY = 'answer_ready';

    public const STATE_UNAVAILABLE = 'unavailable';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_id ??= (string) Str::ulid());
    }
}
