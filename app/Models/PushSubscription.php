<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['endpoint_encrypted' => 'encrypted', 'p256dh_encrypted' => 'encrypted', 'auth_encrypted' => 'encrypted', 'revoked_at_utc' => 'datetime', 'last_used_at_utc' => 'datetime']; }
}
