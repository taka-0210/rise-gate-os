<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiCommonSharedCaptureStream extends Model
{
    protected $attributes = ['version' => 1, 'sequence' => 0];

    public const STATE_RECORDING = 'recording';

    public const STATE_PAUSED = 'paused';

    public const STATE_STOPPED = 'stopped';

    public const STATE_CANCELLED = 'cancelled';

    public const STATE_INTERRUPTED = 'interrupted';

    public const ACTIVE_STATES = [self::STATE_RECORDING, self::STATE_PAUSED];

    protected $fillable = [
        'public_id', 'ai_common_shared_session_id', 'operator_session_participant_id',
        'client_instance_id', 'mode', 'state', 'generation', 'sequence', 'version',
        'operation_id', 'payload_fingerprint', 'started_at_utc', 'paused_at_utc',
        'stopped_at_utc', 'cancelled_at_utc', 'interrupted_at_utc', 'safe_error_code',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer', 'sequence' => 'integer', 'version' => 'integer',
            'started_at_utc' => 'immutable_datetime', 'paused_at_utc' => 'immutable_datetime',
            'stopped_at_utc' => 'immutable_datetime', 'cancelled_at_utc' => 'immutable_datetime',
            'interrupted_at_utc' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $stream) => $stream->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSession::class, 'ai_common_shared_session_id');
    }

    public function operatorParticipant(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSessionParticipant::class, 'operator_session_participant_id');
    }

    public function audioWindows(): HasMany
    {
        return $this->hasMany(AiCommonSharedAudioWindow::class);
    }
}
