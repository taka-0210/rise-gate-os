<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedSpeakerRelation extends Model
{
    protected $fillable = [
        'public_id', 'ai_common_shared_session_id', 'from_segment_id', 'to_segment_id',
        'created_by_user_id', 'operation_id', 'evidence_type', 'evidence_reference', 'status',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $relation) => $relation->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Speaker relations are immutable.'));
        static::deleting(fn () => throw new LogicException('Speaker relations are immutable.'));
    }
}
