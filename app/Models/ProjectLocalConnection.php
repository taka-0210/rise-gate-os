<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectLocalConnection extends Model
{
    protected $fillable = ['project_id', 'user_id', 'directory_name', 'local_path', 'status', 'last_connected_at'];

    protected function casts(): array
    {
        return ['last_connected_at' => 'datetime'];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
