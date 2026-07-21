<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Support\TaskProgress;

class Roadmap extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'public_id',
        'organization_id',
        'workspace_id',
        'project_id',
        'title',
        'purpose',
        'planned_start_date',
        'target_date',
        'planned_start_day',
        'target_day',
        'reached_at',
        'status',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'target_date' => 'date',
            'planned_start_day' => 'integer',
            'target_day' => 'integer',
            'reached_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Roadmap $roadmap): void {
            $roadmap->public_id ??= (string) Str::ulid();
        });
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function improvements(): HasMany
    {
        return $this->hasMany(Improvement::class)->orderBy('roadmap_sort_order')->latest();
    }

    public function taskProgress(): array
    {
        $improvements = $this->relationLoaded('improvements')
            ? $this->improvements
            : $this->improvements()->with('tasks')->get();
        $tasks = $improvements->flatMap(function (Improvement $improvement) {
            return $improvement->relationLoaded('tasks') ? $improvement->tasks : $improvement->tasks()->get();
        });

        return TaskProgress::calculate($tasks);
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => '下書き',
            self::STATUS_ACTIVE => '進行中',
            self::STATUS_COMPLETED => '完了',
            self::STATUS_ARCHIVED => '保管済み',
        ];
    }
}
