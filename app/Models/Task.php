<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Task extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_TODO = 'todo';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_DONE = 'done';
    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'public_id',
        'organization_id',
        'workspace_id',
        'project_id',
        'improvement_id',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'created_by',
        'planned_start_date',
        'due_date',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Task $task): void {
            $task->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
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

    public function improvement(): BelongsTo
    {
        return $this->belongsTo(Improvement::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_TODO => '未着手',
            self::STATUS_IN_PROGRESS => '進行中',
            self::STATUS_DONE => '完了',
            self::STATUS_ARCHIVED => '保管済み',
        ];
    }

    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW => '低',
            self::PRIORITY_NORMAL => '通常',
            self::PRIORITY_HIGH => '高',
            self::PRIORITY_URGENT => '至急',
        ];
    }
}
