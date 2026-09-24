<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMemberRole extends Model
{
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    protected $fillable = [
        'project_member_id', 'role', 'granted_by_user_id', 'granted_at', 'revoked_at', 'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return ['granted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(ProjectMember::class, 'project_member_id');
    }
}
