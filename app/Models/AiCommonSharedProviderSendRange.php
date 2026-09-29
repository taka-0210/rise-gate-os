<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCommonSharedProviderSendRange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['send_ordinal' => 'integer', 'provider_offset_start_sample' => 'integer', 'provider_offset_end_sample' => 'integer', 'sent_at_utc' => 'immutable_datetime', 'acknowledged_at_utc' => 'immutable_datetime'];
    }

    public function sourceRange(): BelongsTo
    {
        return $this->belongsTo(AiCommonSharedSourceRange::class, 'source_range_id');
    }
}
