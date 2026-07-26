<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectActual extends Model
{
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_CONFIRMED = 'confirmed';

    protected $fillable = [
        'public_id', 'project_id', 'source_proposal_id', 'related_entity_type',
        'related_entity_public_id', 'title', 'description', 'result',
        'actual_started_at', 'actual_completed_at', 'effort_minutes',
        'status', 'recorded_by', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'actual_started_at' => 'datetime',
            'actual_completed_at' => 'datetime',
            'effort_minutes' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $actual): void {
            $actual->public_id ??= (string) Str::ulid();
            $actual->status ??= self::STATUS_RECORDED;
            $actual->recorded_at ??= now();
        });
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function sourceProposal(): BelongsTo { return $this->belongsTo(AiProposal::class, 'source_proposal_id'); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
