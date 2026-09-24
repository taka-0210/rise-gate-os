<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectExecutionEvent extends Model
{
    protected $fillable = [
        'project_id', 'entity_type', 'entity_id', 'subject_public_id', 'actor_user_id', 'event', 'source', 'operation_id',
        'entity_version', 'before_data', 'after_data', 'reason', 'occurred_at',
    ];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array', 'occurred_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
