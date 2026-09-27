<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCommonSharedSourceContext extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['participant_version' => 'integer', 'audience_snapshot' => 'array'];
    }
}
