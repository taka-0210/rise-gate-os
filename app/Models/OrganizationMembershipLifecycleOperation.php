<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationMembershipLifecycleOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'organization_user_id',
        'actor_user_id',
        'command',
        'request_id',
        'payload_hash',
        'expected_version',
        'result_status',
        'result_version',
        'result_access_epoch',
        'revoked_ai_key_count',
        'revoked_invitation_count',
    ];

    protected function casts(): array
    {
        return [
            'expected_version' => 'integer',
            'result_version' => 'integer',
            'result_access_epoch' => 'integer',
            'revoked_ai_key_count' => 'integer',
            'revoked_invitation_count' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function organizationMembership(): BelongsTo
    {
        return $this->belongsTo(OrganizationUser::class, 'organization_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
