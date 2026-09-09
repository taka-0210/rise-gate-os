<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAppAccount extends Model
{
    protected $fillable = ['project_app_id', 'login', 'name', 'password', 'role', 'enabled'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'password' => 'hashed'];
    }
}
