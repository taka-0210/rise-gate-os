<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
class ActionScheduleRevision extends Model
{
    protected $guarded = [];
    protected static function booted(): void { static::creating(fn (self $model) => $model->public_id ??= (string) Str::ulid()); }
    protected function casts(): array { return ['weekdays' => 'array', 'month_end' => 'boolean', 'starts_on' => 'date', 'ends_on' => 'date', 'effective_from' => 'date', 'generated_through' => 'date']; }
    public function setting(): BelongsTo { return $this->belongsTo(ActionRunSetting::class, 'action_run_setting_id'); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
}
