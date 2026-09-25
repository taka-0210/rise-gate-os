<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ActionExecutionEvent extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['before_state' => 'array', 'after_state' => 'array', 'occurred_at_utc' => 'datetime']; }
    public function execution(): BelongsTo { return $this->belongsTo(ActionExecution::class, 'action_execution_id'); }
}
