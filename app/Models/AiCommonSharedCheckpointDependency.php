<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class AiCommonSharedCheckpointDependency extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Checkpoint dependencies are immutable.'));
        static::deleting(fn () => throw new LogicException('Checkpoint dependencies are immutable.'));
    }
}
