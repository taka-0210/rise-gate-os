<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnnualManagementPolicyOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id', 'actor_user_id', 'request_id', 'payload_hash', 'operation',
        'annual_management_policy_id', 'result_revision_no', 'result_metadata', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['result_metadata' => 'array', 'result_revision_no' => 'integer', 'completed_at' => 'datetime'];
    }
}
