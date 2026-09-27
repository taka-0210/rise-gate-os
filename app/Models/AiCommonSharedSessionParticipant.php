<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiCommonSharedSessionParticipant extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_LEFT = 'left';

    protected $fillable = [
        'ai_common_shared_session_id', 'ai_common_shared_participant_id', 'user_id',
        'role', 'status', 'participant_audience_epoch', 'membership_access_epoch',
        'credential_generation', 'joined_at_utc', 'left_at_utc',
    ];

    protected function casts(): array
    {
        return [
            'participant_audience_epoch' => 'integer', 'membership_access_epoch' => 'integer',
            'credential_generation' => 'integer', 'joined_at_utc' => 'immutable_datetime',
            'left_at_utc' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSession::class, 'ai_common_shared_session_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedParticipant::class, 'ai_common_shared_participant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(AiCommonSharedSessionConsent::class);
    }
}
