<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceAiSetting extends Model
{
    public const TERMS_VERSION = '2026-07-18-v1';

    public const DEFAULT_DATA_CATEGORIES = [
        'project_metadata', 'roadmaps', 'improvements', 'tasks', 'progress', 'test_results',
    ];

    protected $fillable = [
        'workspace_id', 'enabled', 'provider', 'allowed_data_categories', 'terms_version',
        'enabled_by', 'enabled_at', 'disabled_by', 'disabled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allowed_data_categories' => 'array',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function enabler(): BelongsTo { return $this->belongsTo(User::class, 'enabled_by'); }
}
