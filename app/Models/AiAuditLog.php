<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAuditLog extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'ai_access_key_id', 'project_id', 'ai_proposal_id',
        'event', 'tool_name', 'succeeded', 'duration_ms', 'request_fingerprint',
        'error_message', 'metadata', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'metadata' => 'array', 'occurred_at' => 'datetime'];
    }
}
