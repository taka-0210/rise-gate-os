<?php
namespace App\Http\Controllers;
use App\Models\OrganizationNotificationPolicy;
use App\Models\OrganizationNotificationCalendarDate;
use App\Services\Organization\OrganizationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class OrganizationNotificationPolicyController extends Controller
{
    public function edit(Request $request,OrganizationAccess $access): View
    {
        $company=$request->attributes->get('currentCompany');$access->authorizeManage($request->user(),$company);
        $policy=OrganizationNotificationPolicy::query()->firstOrNew(['organization_id'=>$company->id]);
        $calendarDates=OrganizationNotificationCalendarDate::query()->where('organization_id',$company->id)->orderBy('calendar_date')->get();
        return view('notifications.policy',compact('company','policy','calendarDates'));
    }
    public function update(Request $request,OrganizationAccess $access): RedirectResponse
    {
        $company=$request->attributes->get('currentCompany');$access->authorizeManage($request->user(),$company);
        $data=$request->validate(['is_confirmed'=>['required','boolean'],'weekday_windows'=>['required','array'],'weekday_windows.*.enabled'=>['nullable','boolean'],
            'weekday_windows.*.start'=>['nullable','date_format:H:i'],'weekday_windows.*.end'=>['nullable','date_format:H:i'],'quiet_starts_at'=>['nullable','date_format:H:i'],'quiet_ends_at'=>['nullable','date_format:H:i'],
            'calendar_dates'=>['nullable','string','max:10000']]);
        $calendar=$this->parseCalendar($data['calendar_dates']??'');
        unset($data['calendar_dates']);
        DB::transaction(function()use($company,$request,$data,$calendar){
            OrganizationNotificationPolicy::query()->updateOrCreate(['organization_id'=>$company->id],[...$data,'timezone'=>'Asia/Tokyo','updated_by_user_id'=>$request->user()->id]);
            OrganizationNotificationCalendarDate::query()->where('organization_id',$company->id)->delete();
            foreach($calendar as $row)OrganizationNotificationCalendarDate::query()->create(['organization_id'=>$company->id,...$row]);
        },3);
        return back()->with('status','Organization通知方針を保存しました。');
    }
    private function parseCalendar(string $input): array
    {
        $rows=[];
        foreach(preg_split('/\R/',trim($input))?:[] as $line){
            if(trim($line)==='')continue;
            [$date,$kind,$label]=array_pad(array_map('trim',explode(',',$line,3)),3,null);
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$date)||!in_array($kind,['holiday','working_exception'],true)){
                throw ValidationException::withMessages(['calendar_dates'=>'YYYY-MM-DD,holiday または YYYY-MM-DD,working_exception の形式で入力してください。']);
            }
            $rows[]=['calendar_date'=>$date,'kind'=>$kind,'label'=>$label];
        }
        return $rows;
    }
}
