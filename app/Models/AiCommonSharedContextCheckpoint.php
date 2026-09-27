<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

class AiCommonSharedContextCheckpoint extends Model
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_STALE = 'stale';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['structured_context' => 'encrypted', 'revision_no' => 'integer', 'estimated_tokens' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $row) => $row->public_id ??= (string) Str::ulid());
        static::updating(function (self $row): void {
            if (array_keys($row->getDirty()) !== ['status']) {
                throw new LogicException('Context checkpoints are immutable except for fail-closed stale disposition.');
            }
        });
        static::deleting(fn () => throw new LogicException('Context checkpoints are immutable.'));
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(AiCommonSharedCheckpointDependency::class, 'ai_common_shared_context_checkpoint_id');
    }
}
