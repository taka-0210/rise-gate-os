<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AiProposal extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'public_id', 'organization_id', 'workspace_id', 'project_id', 'source',
        'idempotency_key', 'title', 'summary', 'status', 'evidence',
        'requested_by', 'reviewed_by', 'reviewed_at', 'applied_at', 'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (AiProposal $proposal): void {
            $proposal->public_id ??= (string) Str::ulid();
        });
    }

    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function items(): HasMany { return $this->hasMany(AiProposalItem::class)->orderBy('sort_order')->orderBy('id'); }
    public function aiRequest() { return $this->hasOne(AiRequest::class); }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING => '承認待ち',
            self::STATUS_APPROVED => '承認済み',
            self::STATUS_REJECTED => '却下',
            self::STATUS_APPLIED => '反映済み',
            self::STATUS_FAILED => '反映失敗',
        ];
    }
}
