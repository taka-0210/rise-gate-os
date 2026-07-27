<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectHandoff extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_CODEX = 'codex';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'public_id',
        'project_id',
        'source',
        'status',
        'completed_work',
        'next_work',
        'idempotency_key',
        'proposed_by',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (ProjectHandoff $handoff): void {
            $handoff->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
