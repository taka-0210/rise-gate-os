<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagementDesignAccessSetting extends Model
{
    use HasFactory;

    public const VIEW_SCOPE_EXPLICIT = 'explicit';

    public const VIEW_SCOPE_ALL_ACTIVE_STAFF = 'all_active_staff';

    public const VIEW_SCOPES = [self::VIEW_SCOPE_EXPLICIT, self::VIEW_SCOPE_ALL_ACTIVE_STAFF];

    protected $fillable = ['organization_id', 'item_type', 'view_scope', 'updated_by_user_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
