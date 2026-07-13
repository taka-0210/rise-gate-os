<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMember extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';
    public const ROLE_PROJECT_MANAGER = 'project_manager';
    public const ROLE_DESIGNER = 'designer';
    public const ROLE_CODER = 'coder';
    public const ROLE_REVIEWER = 'reviewer';
    public const ROLE_MENTOR = 'mentor';
    public const ROLE_CLIENT = 'client';
    public const ROLE_VIEWER = 'viewer';

    public const PERMISSION_ADMIN = 'admin';
    public const PERMISSION_EDIT = 'edit';
    public const PERMISSION_COMMENT = 'comment';
    public const PERMISSION_VIEW = 'view';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INVITED = 'invited';

    protected $fillable = [
        'project_id',
        'user_id',
        'workspace_id',
        'project_role',
        'permission_level',
        'invited_by',
        'invited_at',
        'accepted_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public static function roles(): array
    {
        return [
            self::ROLE_OWNER => 'オーナー',
            self::ROLE_PROJECT_MANAGER => 'Project管理者',
            self::ROLE_DESIGNER => 'デザイナー',
            self::ROLE_CODER => '実装担当',
            self::ROLE_REVIEWER => '確認担当',
            self::ROLE_MENTOR => 'メンター',
            self::ROLE_CLIENT => 'お客様',
            self::ROLE_VIEWER => '閲覧者',
        ];
    }

    public static function permissions(): array
    {
        return [
            self::PERMISSION_ADMIN => '管理',
            self::PERMISSION_EDIT => '編集',
            self::PERMISSION_COMMENT => 'コメント',
            self::PERMISSION_VIEW => '閲覧',
        ];
    }
}
