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

    public static function executionStatuses(): array
    {
        return [
            self::STATUS_TODO => 'Not started',
            self::STATUS_IN_PROGRESS => 'In progress',
            self::STATUS_REVIEW_PENDING => 'Review pending',
            self::STATUS_DONE => 'Done',
            self::STATUS_ARCHIVED => 'Archived',
        ];
    }

    public const STATUS_TODO = 'todo';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_DONE = 'done';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_REVIEW_PENDING = 'review_pending';

    public const REVIEW_NOT_REQUIRED = 'not_required';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_CONFIRMED = 'confirmed';

    public const REVIEW_REJECTED = 'rejected';

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
        'sort_order',
        'title',
        'description',
        'status',
        'priority',
        'assigned_to',
        'created_by',
        'planned_start_date',
        'due_date',
        'planned_start_day',
        'due_day',
        'completed_at',
        'done_condition',
        'reviewer_user_id',
        'review_status',
        'review_requested_by_user_id',
        'review_requested_at',
        'reviewed_by_user_id',
        'reviewed_at',
        'completed_by_user_id',
        'reopened_by_user_id',
        'reopened_at',
        'last_change_reason',
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
            'plan_version' => 'integer',
            'planned_start_date' => 'date',
            'due_date' => 'date',
            'planned_start_day' => 'integer',
            'due_day' => 'integer',
            'sort_order' => 'integer',
            'completed_at' => 'datetime',
            'review_requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'reopened_at' => 'datetime',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
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
            self::STATUS_REVIEW_PENDING => '確認待ち',
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
