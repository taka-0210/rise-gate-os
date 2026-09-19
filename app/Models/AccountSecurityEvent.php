<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountSecurityEvent extends Model
{
    protected $fillable = [
        'user_id',
        'actor_user_id',
        'event',
        'outcome',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
