<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedSessionConsent extends Model
{
    public const PURPOSE_RECORDING = 'recording';

    public const PURPOSE_EXTERNAL_ASR = 'external_asr';

    public const PURPOSE_TRANSCRIPT_SHARING = 'transcript_sharing';

    public const PURPOSE_AI_REFERENCE = 'ai_reference';

    public const PURPOSES = [
        self::PURPOSE_RECORDING,
        self::PURPOSE_EXTERNAL_ASR,
        self::PURPOSE_TRANSCRIPT_SHARING,
        self::PURPOSE_AI_REFERENCE,
    ];

    public const STATUS_GRANTED = 'granted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'public_id', 'ai_common_shared_session_participant_id', 'decided_by_user_id',
        'purpose', 'status', 'revision_no', 'operation_id', 'payload_fingerprint',
        'evidence_version', 'decided_at_utc', 'revoked_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'revision_no' => 'integer', 'decided_at_utc' => 'immutable_datetime',
            'revoked_at_utc' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $consent) => $consent->public_id ??= (string) Str::ulid());
        static::updating(fn () => throw new LogicException('Consent revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Consent revisions are immutable.'));
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSessionParticipant::class, 'ai_common_shared_session_participant_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
