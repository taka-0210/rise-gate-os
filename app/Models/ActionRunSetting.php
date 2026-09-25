<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ActionRunSetting extends Model
{
    public const TYPE_ONE_TIME = 'one_time';
    public const TYPE_CONTINUOUS = 'continuous';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    protected $guarded = [];
    protected function casts(): array { return ['row_version' => 'integer']; }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function currentRevision(): BelongsTo { return $this->belongsTo(ActionScheduleRevision::class, 'current_revision_id'); }
    public function revisions(): HasMany { return $this->hasMany(ActionScheduleRevision::class); }
}
