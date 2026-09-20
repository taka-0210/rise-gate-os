<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OwnerOnboardingOperation extends Model
{
    protected $fillable = ['owner_onboarding_id', 'actor_user_id', 'operation', 'request_id', 'payload_hash'];
}
