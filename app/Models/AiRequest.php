<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AiRequest extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = ['public_id','organization_id','workspace_id','project_id','requested_by','claimed_by_access_key_id','ai_proposal_id','title','instructions','status','claimed_at','completed_at','failure_reason'];
    protected function casts(): array { return ['claimed_at'=>'datetime','completed_at'=>'datetime']; }
    protected static function booted(): void { static::creating(fn (self $request) => $request->public_id ??= (string) Str::ulid()); }
    public function project() { return $this->belongsTo(Project::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function proposal() { return $this->belongsTo(AiProposal::class, 'ai_proposal_id'); }
}
