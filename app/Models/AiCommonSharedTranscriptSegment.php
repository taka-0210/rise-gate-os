<?php

namespace App\Models;

use App\Services\AiCommon\Realtime\RealtimeTranscriptSourceGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedTranscriptSegment extends Model
{
    public const SOURCE_BOUNDED = 'bounded_audio';

    public const SOURCE_REALTIME = 'realtime_source';

    public const SPEAKER_UNKNOWN = 'Unknown';

    protected $fillable = [
        'public_id', 'ai_common_shared_session_id', 'ai_common_shared_audio_window_id',
        'ai_common_shared_capture_stream_id', 'source_kind', 'realtime_durable_final_commit_id', 'segment_index', 'speaker_label',
        'speaker_scope', 'range_start_ms', 'range_end_ms', 'confidence', 'current_revision_id',
    ];

    protected function casts(): array
    {
        return [
            'segment_index' => 'integer', 'range_start_ms' => 'integer',
            'range_end_ms' => 'integer', 'confidence' => 'decimal:5',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $segment): void {
            $segment->public_id ??= (string) Str::ulid();
            $segment->source_kind ??= self::SOURCE_BOUNDED;
            if ($segment->source_kind === self::SOURCE_BOUNDED && $segment->ai_common_shared_audio_window_id !== null) {
                return;
            }
            if ($segment->source_kind === self::SOURCE_REALTIME && app(RealtimeTranscriptSourceGuard::class)->allows($segment)) {
                return;
            }
            throw new LogicException('Transcript source lineage must be created by its authorized Writer.');
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSession::class, 'ai_common_shared_session_id');
    }

    public function audioWindow(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedAudioWindow::class, 'ai_common_shared_audio_window_id');
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedTranscriptRevision::class, 'current_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(AiCommonSharedTranscriptRevision::class);
    }

    public function identityRevisions(): HasMany
    {
        return $this->hasMany(AiCommonSharedIdentityRevision::class);
    }
}
