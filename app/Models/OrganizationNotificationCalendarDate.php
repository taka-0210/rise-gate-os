<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrganizationNotificationCalendarDate extends Model
{
    public const HOLIDAY='holiday';
    public const WORKING_EXCEPTION='working_exception';
    protected $guarded=[];
    protected function casts(): array{return ['calendar_date'=>'date'];}
}
