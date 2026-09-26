<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganizationNotificationPolicy extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['is_confirmed' => 'boolean', 'weekday_windows' => 'array']; }
}
