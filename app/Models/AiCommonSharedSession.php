<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiCommonSharedSession extends Model
{
    protected $attributes = ['version' => 1, 'sequence' => 0];

    public const MODE_SHARED_ROOM = 'shared_room';

    public const STATE_PREPARED = 'prepared';

    public const STATE_ACTIVE = 'active';

    public const STATE_PAUSED = 'paused';

    public const STATE_INTERRUPTED = 'interrupted';

    public const STATE_ENDING = 'ending';

    public const STATE_ENDED = 'ended';

    protected $fillable = [
        'public_id', 'organization_id', 'ai_common_shared_conversation_id', 'host_participant_id',
        'purpose_revision_id', 'mode', 'state', 'version', 'sequence', 'participant_version',
        'operation_id', 'payload_fingerprint', 'started_at_utc', 'paused_at_utc',
        'interrupted_at_utc', 'ending_at_utc', 'ended_at_utc', 'end_cutoff_sequence',
        'hard_stop_at_utc', 'safe_error_code',
        'context_current_checkpoint_id', 'context_watermark_segment_id',
        'context_watermark_revision_id', 'context_dirty_from_segment_id',
        'context_status', 'room_sequence',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'sequence' => 'integer', 'participant_version' => 'integer',
            'end_cutoff_sequence' => 'integer', 'started_at_utc' => 'immutable_datetime',
            'paused_at_utc' => 'immutable_datetime', 'interrupted_at_utc' => 'immutable_datetime',
            'ending_at_utc' => 'immutable_datetime', 'ended_at_utc' => 'immutable_datetime',
            'hard_stop_at_utc' => 'immutable_datetime',
            'context_current_checkpoint_id' => 'integer', 'context_watermark_segment_id' => 'integer',
            'context_watermark_revision_id' => 'integer', 'context_dirty_from_segment_id' => 'integer',
            'room_sequence' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $session) => $session->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function sharedConversation(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedConversation::class, 'ai_common_shared_conversation_id');
    }

    public function hostParticipant(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedParticipant::class, 'host_participant_id');
    }

    public function purposeRevision(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedPurposeRevision::class, 'purpose_revision_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(AiCommonSharedSessionParticipant::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(AiCommonSharedCaptureStream::class);
    }

    public function audioWindows(): HasMany
    {
        return $this->hasMany(AiCommonSharedAudioWindow::class);
    }

    public function transcriptSegments(): HasMany
    {
        return $this->hasMany(AiCommonSharedTranscriptSegment::class);
    }
}
