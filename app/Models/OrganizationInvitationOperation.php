<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationInvitationOperation extends Model
{
    protected $fillable = [
        'organization_id',
        'organization_invitation_id',
        'actor_user_id',
        'operation',
        'request_id',
        'payload_hash',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvitation::class, 'organization_invitation_id');
    }
}
