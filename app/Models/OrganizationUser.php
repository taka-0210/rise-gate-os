<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationUser extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    public const COMPANY_ROLE_OWNER = 'owner';
    public const COMPANY_ROLE_EXECUTIVE = 'executive';
    public const COMPANY_ROLE_ACCOUNTING = 'accounting';
    public const COMPANY_ROLE_MANAGER = 'manager';
    public const COMPANY_ROLE_MEMBER = 'member';

    public const PERMISSION_MEMBERS_MANAGE = 'company.members.manage';
    public const PERMISSION_FINANCE_VIEW_PL = 'finance.pl.view';
    public const PERMISSION_FINANCE_IMPORT_PL = 'finance.pl.import';
    public const PERMISSION_FINANCE_MANAGE_PL = 'finance.pl.manage';
    public const PERMISSION_FINANCE_VIEW_BS = 'finance.bs.view';
    public const PERMISSION_FINANCE_VIEW_DEBT = 'finance.debt.view';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'company_role',
        'permissions',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'permissions' => 'array',
        ];
    }

    public static function companyRoles(): array
    {
        return [
            self::COMPANY_ROLE_OWNER => '経営者',
            self::COMPANY_ROLE_EXECUTIVE => '役員',
            self::COMPANY_ROLE_ACCOUNTING => '経理',
            self::COMPANY_ROLE_MANAGER => '部門責任者',
            self::COMPANY_ROLE_MEMBER => '一般社員',
        ];
    }

    public static function permissionLabels(): array
    {
        return [
            self::PERMISSION_MEMBERS_MANAGE => '会社ユーザーと権限を管理',
            self::PERMISSION_FINANCE_VIEW_PL => '全社P/Lを閲覧',
            self::PERMISSION_FINANCE_IMPORT_PL => '年度別P/Lを取り込む',
            self::PERMISSION_FINANCE_MANAGE_PL => '年度別P/Lを入力・編集・確定',
            self::PERMISSION_FINANCE_VIEW_BS => '全社B/Sを閲覧',
            self::PERMISSION_FINANCE_VIEW_DEBT => '借入・返済情報を閲覧',
        ];
    }

    public static function adminPermissions(): array
    {
        return [
            self::PERMISSION_MEMBERS_MANAGE,
            self::PERMISSION_FINANCE_VIEW_PL,
            self::PERMISSION_FINANCE_IMPORT_PL,
            self::PERMISSION_FINANCE_MANAGE_PL,
        ];
    }

    public static function defaultCompanyRole(string $membershipRole): string
    {
        return $membershipRole === self::ROLE_OWNER
            ? self::COMPANY_ROLE_OWNER
            : self::COMPANY_ROLE_MEMBER;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
