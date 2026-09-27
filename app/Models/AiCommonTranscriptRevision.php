<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AiCommonTranscriptRevision extends Model
{
    public const KIND_PROVIDER = 'provider';

    public const KIND_HUMAN = 'human';

    protected $fillable = [
        'public_id', 'ai_common_attachment_id', 'created_by_user_id', 'parent_revision_id',
        'operation_id', 'payload_fingerprint',
        'revision_number', 'kind', 'content', 'content_sha256', 'audio_sha256',
        'range_start_ms', 'range_end_ms', 'range_precision', 'provider', 'model',
    ];

    protected function casts(): array
    {
        return [
            'revision_number' => 'integer',
            'content' => 'encrypted',
            'range_start_ms' => 'integer',
            'range_end_ms' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Transcript revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Transcript revisions are immutable.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AiCommonAttachment::class, 'ai_common_attachment_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_revision_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
