<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagementDesignOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'actor_user_id', 'request_id', 'payload_hash', 'operation',
        'management_design_item_id', 'result_revision_no', 'result_metadata', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result_revision_no' => 'integer',
            'result_metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ManagementDesignItem::class, 'management_design_item_id');
    }
}
