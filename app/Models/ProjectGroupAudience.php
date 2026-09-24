<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectGroupAudience extends Model
{
    protected $fillable = ['project_id', 'organization_group_id', 'added_by_user_id'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(OrganizationGroup::class, 'organization_group_id');
    }
}
