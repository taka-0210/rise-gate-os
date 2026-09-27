<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedTranscriptRevision extends Model
{
    public const KIND_PROVIDER = 'provider';

    public const KIND_HUMAN = 'human';

    protected $fillable = [
        'public_id', 'ai_common_shared_transcript_segment_id', 'parent_revision_id',
        'created_by_user_id', 'revision_no', 'kind', 'operation_id', 'payload_fingerprint',
        'content', 'content_sha256', 'range_start_ms', 'range_end_ms', 'provider', 'model',
    ];

    protected function casts(): array
    {
        return [
            'revision_no' => 'integer', 'content' => 'encrypted',
            'range_start_ms' => 'integer', 'range_end_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $revision) => $revision->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Shared Transcript revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Shared Transcript revisions are immutable.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedTranscriptSegment::class, 'ai_common_shared_transcript_segment_id');
    }
}
