<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommonTranscriptionOperation extends Model
{
    public const RESULT_PROCESSING = 'processing';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILED = 'failed';

    public const RESULT_UNKNOWN = 'unknown';

    public const RESULT_DISCARDED = 'discarded';

    protected $fillable = [
        'ai_common_temporary_audio_id', 'actor_user_id', 'operation_id',
        'payload_fingerprint', 'logical_request_id', 'provider', 'model',
        'result_status', 'safe_error_code', 'latency_ms',
    ];

    protected function casts(): array
    {
        return ['latency_ms' => 'integer'];
    }

    public function audio(): BelongsTo
    {
        return $this->belongsTo(AiCommonTemporaryAudio::class, 'ai_common_temporary_audio_id');
    }
}
