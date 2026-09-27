<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommonSharedCoState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer', 'version' => 'integer', 'session_sequence' => 'integer',
            'context_watermark_segment_id' => 'integer', 'room_sequence' => 'integer',
        ];
    }

    public function currentSession(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSession::class, 'current_session_id');
    }
}
