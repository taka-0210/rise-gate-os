<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrganizationUser extends Model
{
    use HasFactory;

    protected $attributes = [
        'membership_status' => self::STATUS_ACTIVE,
        'access_epoch' => 1,
        'lifecycle_version' => 1,
    ];

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    public const ROLE_VIEWER = 'viewer';

    public const ORGANIZATION_ROLE_OWNER = 'owner';

    public const ORGANIZATION_ROLE_ADMIN = 'admin';

    public const ORGANIZATION_ROLE_MEMBER = 'member';

    public const STATUS_INVITED = 'invited';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_LEFT = 'left';

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

    public const PERMISSION_FINANCE_MANAGE_DEBT = 'finance.debt.manage';

    protected $fillable = [
        'organization_id',
        'user_id',
        'role',
        'organization_role',
        'position',
        'membership_status',
        'access_epoch',
        'lifecycle_version',
        'status_changed_at',
        'status_changed_by_user_id',
        'status_change_reason',
        'company_role',
        'permissions',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'access_epoch' => 'integer',
            'lifecycle_version' => 'integer',
            'status_changed_at' => 'datetime',
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

    public static function organizationRoles(): array
    {
        return [
            self::ORGANIZATION_ROLE_OWNER => 'Owner',
            self::ORGANIZATION_ROLE_ADMIN => 'Admin',
            self::ORGANIZATION_ROLE_MEMBER => 'Member',
        ];
    }

    public static function membershipStatuses(): array
    {
        return [
            self::STATUS_INVITED => '招待中',
            self::STATUS_ACTIVE => '利用中',
            self::STATUS_SUSPENDED => '一時停止',
            self::STATUS_LEFT => '退職・所属終了',
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
            self::PERMISSION_FINANCE_MANAGE_DEBT => '借入・返済情報を入力・編集・確定',
        ];
    }

    public static function adminPermissions(): array
    {
        return [
            self::PERMISSION_MEMBERS_MANAGE,
            self::PERMISSION_FINANCE_VIEW_PL,
            self::PERMISSION_FINANCE_IMPORT_PL,
            self::PERMISSION_FINANCE_MANAGE_PL,
            self::PERMISSION_FINANCE_VIEW_DEBT,
            self::PERMISSION_FINANCE_MANAGE_DEBT,
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

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by_user_id');
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(OrganizationGroupMembership::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            OrganizationGroup::class,
            'organization_group_memberships',
            'organization_user_id',
            'organization_group_id',
        )->withPivot(['added_by'])->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function lifecycleOperations(): HasMany
    {
        return $this->hasMany(OrganizationMembershipLifecycleOperation::class);
    }

    public function businessDomainEditorGrant(): HasOne
    {
        return $this->hasOne(BusinessDomainEditorGrant::class);
    }

    public function productOrganizationCompatibility(): HasOne
    {
        return $this->hasOne(ProductOrganizationCompatibility::class);
    }
}
