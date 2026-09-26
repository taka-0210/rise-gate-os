<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDeliveryAttempt extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['attempted_at_utc' => 'datetime']; }
    public function delivery(): BelongsTo { return $this->belongsTo(NotificationDelivery::class); }
}
