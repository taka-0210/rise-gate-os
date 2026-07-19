<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectInternalNoteReference extends Model
{
    protected $fillable = ['project_internal_note_id', 'project_id', 'url', 'title', 'reference_points', 'avoid_points', 'share_with_ai'];

    protected function casts(): array
    {
        return ['share_with_ai' => 'boolean'];
    }

    public function note(): BelongsTo { return $this->belongsTo(ProjectInternalNote::class, 'project_internal_note_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
}
