<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerOnboardingAuditEvent extends Model
{
    protected $fillable = [
        'owner_onboarding_id', 'actor_user_id', 'subject_user_id',
        'event', 'outcome', 'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }
}
