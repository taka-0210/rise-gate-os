<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectInternalNoteAttachment extends Model
{
    protected $fillable = [
        'public_id', 'project_internal_note_id', 'project_id', 'uploaded_by',
        'original_name', 'stored_path', 'mime_type', 'extension', 'size_bytes', 'sha256',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $attachment) => $attachment->public_id ??= (string) Str::ulid());
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(ProjectInternalNote::class, 'project_internal_note_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
