<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Estimate extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_VOID = 'void';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (Estimate $estimate) => $estimate->public_id ??= (string) Str::ulid());
        static::created(function (Estimate $estimate): void {
            if (! $estimate->revision_group) {
                $estimate->forceFill(['revision_group' => $estimate->public_id])->saveQuietly();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'valid_until' => 'date',
            'issuer_snapshot' => 'array',
            'client_snapshot' => 'array',
            'is_current' => 'boolean',
            'submitted_at' => 'datetime',
            'client_viewed_at' => 'datetime',
            'responded_at' => 'datetime',
            'ordered_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT => '下書き',
            self::STATUS_ISSUED => '発行済み',
            self::STATUS_SUBMITTED => '提出済み',
            self::STATUS_PENDING => '承認待ち',
            self::STATUS_ACCEPTED => '受注',
            self::STATUS_REJECTED => '失注',
            self::STATUS_EXPIRED => '期限切れ',
            self::STATUS_VOID => '無効',
        ];
    }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function items(): HasMany { return $this->hasMany(EstimateItem::class)->orderBy('sort_order'); }
}
