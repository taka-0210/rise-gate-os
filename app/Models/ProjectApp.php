<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProjectApp extends Model
{
    protected $fillable = ['project_id', 'created_by', 'name', 'html', 'version'];

    protected static function booted(): void
    {
        static::creating(function (ProjectApp $app): void {
            $app->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
