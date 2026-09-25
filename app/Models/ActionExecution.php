<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
class ActionExecution extends Model
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_MISSED = 'missed';
    public const STATUS_SKIPPED = 'skipped';
    protected $guarded = [];
    protected static function booted(): void { static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid()); }
    protected function casts(): array { return ['scheduled_date' => 'date', 'window_starts_at_utc' => 'datetime', 'window_ends_at_utc' => 'datetime', 'performed_at_utc' => 'datetime', 'reported_at_utc' => 'datetime', 'resolved_at_utc' => 'datetime', 'row_version' => 'integer']; }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function revision(): BelongsTo { return $this->belongsTo(ActionScheduleRevision::class, 'schedule_revision_id'); }
    public function source(): BelongsTo { return $this->belongsTo(self::class, 'source_execution_id'); }
    public function events(): HasMany { return $this->hasMany(ActionExecutionEvent::class); }
}
