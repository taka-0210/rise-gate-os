<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImprovementOutput extends Model
{
    use HasFactory;

    public const TYPE_TASK = 'task';
    public const TYPE_PROJECT = 'project';

    protected $fillable = [
        'improvement_id',
        'output_type',
        'output_id',
        'created_by',
    ];

    public function improvement(): BelongsTo
    {
        return $this->belongsTo(Improvement::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function types(): array
    {
        return [
            self::TYPE_TASK => 'Task',
            self::TYPE_PROJECT => 'Project',
        ];
    }
}