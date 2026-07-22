<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiChatThread extends Model
{
    protected $fillable = ['public_id', 'organization_id', 'workspace_id', 'project_id', 'user_id', 'title'];

    protected static function booted(): void
    {
        static::creating(fn (self $thread) => $thread->public_id ??= (string) Str::ulid());
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function messages(): HasMany { return $this->hasMany(AiChatMessage::class)->oldest(); }
}
