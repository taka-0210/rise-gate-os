<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLegalConsent extends Model
{
    public const PURPOSE_OWNER_ONBOARDING = 'owner_onboarding';

    protected $fillable = [
        'user_id', 'owner_onboarding_id', 'purpose', 'terms_version',
        'terms_content_hash', 'privacy_version', 'privacy_content_hash',
        'document_signature',
        'recorded_timezone', 'consented_at',
    ];

    protected function casts(): array
    {
        return ['consented_at' => 'datetime'];
    }
}
