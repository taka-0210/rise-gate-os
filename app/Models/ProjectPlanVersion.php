<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectPlanVersion extends Model
{
    public const TYPE_PROPOSAL = 'proposal';
    public const TYPE_PROPOSAL_BEFORE = 'proposal_before';
    public const TYPE_TIMELINE = 'timeline';

    protected $fillable = [
        'public_id', 'project_id', 'version_number', 'source_proposal_id',
        'version_type', 'title', 'note', 'change_summary',
        'previous_snapshot', 'plan_snapshot', 'created_by', 'created_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'previous_snapshot' => 'array',
            'plan_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->public_id ??= (string) Str::ulid();
            $version->created_at ??= now();
        });
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function sourceProposal(): BelongsTo { return $this->belongsTo(AiProposal::class, 'source_proposal_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function timelineSnapshot(): array
    {
        if ($this->version_type === self::TYPE_PROPOSAL) {
            return $this->previous_snapshot ?? [];
        }

        return $this->plan_snapshot ?? [];
    }
}
