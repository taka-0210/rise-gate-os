<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationDelivery extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['available_at_utc' => 'datetime', 'leased_until_utc' => 'datetime', 'delivered_at_utc' => 'datetime']; }
    public function notification(): BelongsTo { return $this->belongsTo(CompanyNotification::class, 'company_notification_id'); }
    public function attempts(): HasMany { return $this->hasMany(NotificationDeliveryAttempt::class); }
}
