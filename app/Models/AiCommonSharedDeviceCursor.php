<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCommonSharedDeviceCursor extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_sequence' => 'integer', 'version' => 'integer', 'last_seen_at_utc' => 'immutable_datetime'];
    }
}
