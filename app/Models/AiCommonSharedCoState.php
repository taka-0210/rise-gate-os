<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCommonSharedCoState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'version' => 'integer'];
    }
}
