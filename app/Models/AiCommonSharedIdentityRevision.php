<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedIdentityRevision extends Model
{
    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'public_id', 'ai_common_shared_transcript_segment_id', 'parent_revision_id',
        'confirmed_user_id', 'created_by_user_id', 'revision_no', 'operation_id',
        'status', 'evidence_type', 'confirmed_at_utc',
    ];

    protected function casts(): array
    {
        return ['revision_no' => 'integer', 'confirmed_at_utc' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $revision) => $revision->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Identity revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Identity revisions are immutable.'));
    }
}
