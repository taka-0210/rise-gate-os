<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiCommonSharedAudioWindow extends Model
{
    public const STATE_RECORDED = 'recorded';

    public const STATE_TRANSCRIBING = 'transcribing';

    public const STATE_TRANSCRIBED = 'transcribed';

    public const STATE_FAILED = 'failed';

    public const STATE_UNKNOWN = 'unknown';

    public const STATE_DISCARDED = 'discarded';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_EXPIRED = 'expired';

    public const CLEANUP_PENDING = 'pending';

    public const CLEANUP_COMPLETE = 'complete';

    protected $fillable = [
        'public_id', 'ai_common_shared_session_id', 'ai_common_shared_capture_stream_id',
        'actor_user_id', 'operation_id', 'payload_fingerprint', 'generation', 'sequence',
        'state', 'version', 'storage_key', 'mime_type', 'extension', 'size_bytes',
        'sha256', 'codec', 'duration_ms', 'logical_request_id', 'provider', 'model',
        'transcription_operation_id', 'transcription_payload_fingerprint',
        'result_status', 'safe_error_code', 'expires_at_utc', 'cleanup_status', 'cleaned_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer', 'sequence' => 'integer', 'version' => 'integer',
            'size_bytes' => 'integer', 'duration_ms' => 'integer',
            'expires_at_utc' => 'immutable_datetime', 'cleaned_at_utc' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $window) => $window->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSession::class, 'ai_common_shared_session_id');
    }

    public function stream(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedCaptureStream::class, 'ai_common_shared_capture_stream_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(AiCommonSharedTranscriptSegment::class);
    }
}
