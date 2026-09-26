<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserNotificationPreference extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['in_app_enabled' => 'boolean', 'push_enabled' => 'boolean', 'email_enabled' => 'boolean', 'email_fallback_enabled' => 'boolean']; }
}
