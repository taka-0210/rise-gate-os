<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Support\TaskProgress;

class Improvement extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_IMPLEMENTED = 'implemented';
    public const STATUS_MEASURED = 'measured';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_ARCHIVED = 'archived';

    public const VISIBILITY_INTERNAL = 'internal';
    public const VISIBILITY_PROJECT = 'project';
    public const VISIBILITY_CLIENT = 'client';

    protected $fillable = [
        'public_id',
        'organization_id',
        'workspace_id',
        'project_id',
        'roadmap_id',
        'roadmap_sort_order',
        'title',
        'current_state',
        'desired_state',
        'problem',
        'hypothesis',
        'action',
        'result',
        'impact',
        'next_action',
        'planned_effort_days',
        'planned_start_date',
        'target_date',
        'planned_start_day',
        'target_day',
        'completed_at',
        'status',
        'visibility',
        'proposed_by',
        'assigned_to',
        'implemented_by',
        'implemented_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Improvement $improvement): void {
            $improvement->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'implemented_at' => 'datetime',
            'planned_start_date' => 'date',
            'target_date' => 'date',
            'planned_start_day' => 'integer',
            'target_day' => 'integer',
            'completed_at' => 'date',
            'planned_effort_days' => 'decimal:2',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function implementer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'implemented_by');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function taskProgress(): array
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks()->get();

        return TaskProgress::calculate($tasks);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(ImprovementOutput::class);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PROPOSED => '提案中',
            self::STATUS_PLANNED => '計画中',
            self::STATUS_IN_PROGRESS => '実行中',
            self::STATUS_IMPLEMENTED => '実施済み',
            self::STATUS_MEASURED => '効果確認済み',
            self::STATUS_CLOSED => '完了',
            self::STATUS_ARCHIVED => '保管済み',
        ];
    }

    public static function visibilities(): array
    {
        return [
            self::VISIBILITY_INTERNAL => '社内のみ',
            self::VISIBILITY_PROJECT => 'Project参加者',
            self::VISIBILITY_CLIENT => 'お客様にも公開',
        ];
    }
}
