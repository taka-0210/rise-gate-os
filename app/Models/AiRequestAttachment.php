<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AiRequestAttachment extends Model
{
    protected $fillable = [
        'public_id', 'ai_request_id', 'workspace_id', 'project_id', 'uploaded_by',
        'original_name', 'stored_path', 'mime_type', 'extension', 'size_bytes', 'sha256',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $attachment) => $attachment->public_id ??= (string) Str::ulid());
    }

    public function aiRequest()
    {
        return $this->belongsTo(AiRequest::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
