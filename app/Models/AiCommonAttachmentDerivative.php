<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AiCommonAttachmentDerivative extends Model
{
    public const KIND_TEXT_EXTRACT = 'text_extract';

    public const STATE_READY = 'ready';

    public const STATE_FAILED = 'failed';

    protected $fillable = [
        'public_id', 'ai_common_attachment_id', 'created_by_user_id', 'operation_id',
        'payload_fingerprint', 'kind', 'state', 'attachment_version', 'source_sha256',
        'selector', 'selector_fingerprint', 'extractor_driver', 'extractor_version',
        'content', 'content_sha256', 'character_count', 'safe_error_code',
    ];

    protected function casts(): array
    {
        return [
            'attachment_version' => 'integer',
            'selector' => 'array',
            'content' => 'encrypted',
            'character_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Attachment derivatives are immutable.'));
        static::deleting(fn () => throw new LogicException('Attachment derivatives are immutable.'));
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(AiCommonAttachment::class, 'ai_common_attachment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
