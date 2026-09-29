<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCommonSharedRelayControlEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_generation' => 'integer', 'cutoff_sample' => 'integer', 'occurred_at_utc' => 'immutable_datetime', 'delivered_at_utc' => 'immutable_datetime', 'acknowledged_at_utc' => 'immutable_datetime'];
    }
}
