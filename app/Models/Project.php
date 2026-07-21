<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_HOLD = 'on_hold';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'public_id',
        'organization_id',
        'owning_workspace_id',
        'billing_workspace_id',
        'client_id',
        'owner_user_id',
        'name',
        'code',
        'summary',
        'current_state',
        'desired_future_state',
        'status',
        'priority',
        'start_date',
        'due_date',
        'duration_days',
        'published_at',
        'completed_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Project $project): void {
            $project->public_id ??= (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'duration_days' => 'integer',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function owningWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'owning_workspace_id');
    }

    public function billingWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'billing_workspace_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function improvements(): HasMany
    {
        return $this->hasMany(Improvement::class);
    }

    public function roadmaps(): HasMany
    {
        return $this->hasMany(Roadmap::class)->orderBy('sort_order')->latest();
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function aiProposals(): HasMany
    {
        return $this->hasMany(AiProposal::class);
    }

    public function aiRequests(): HasMany
    {
        return $this->hasMany(AiRequest::class)->latest();
    }

    public function internalNotes(): HasMany
    {
        return $this->hasMany(ProjectInternalNote::class)->latest();
    }

    public function sourceImprovementOutput(): HasOne
    {
        return $this->hasOne(ImprovementOutput::class, 'output_id')
            ->where('output_type', ImprovementOutput::TYPE_PROJECT);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => '準備中',
            self::STATUS_PROPOSED => '提案済み',
            self::STATUS_ACTIVE => '進行中',
            self::STATUS_ON_HOLD => '保留',
            self::STATUS_COMPLETED => 'ひと区切り',
            self::STATUS_ARCHIVED => '継続フォロー',
        ];
    }

    public static function priorities(): array
    {
        return [
            self::PRIORITY_LOW => '低',
            self::PRIORITY_NORMAL => '通常',
            self::PRIORITY_HIGH => '高',
            self::PRIORITY_URGENT => '緊急',
        ];
    }
}
