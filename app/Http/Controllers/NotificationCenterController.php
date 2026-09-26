<?php
namespace App\Http\Controllers;
use App\Models\CompanyNotification;
use App\Models\UserNotificationPreference;
use App\Services\Notification\NotificationAuthorization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
class NotificationCenterController extends Controller
{
    public function index(Request $request): View
    {
        $company=$request->attributes->get('currentCompany');
        $notifications=CompanyNotification::query()->where('organization_id',$company->id)->where('recipient_user_id',$request->user()->id)
            ->whereNull('cancelled_at_utc')->where('content_visible_at_utc','<=',now('UTC'))->latest('id')->paginate(30);
        $preference=UserNotificationPreference::query()->firstOrNew(['organization_id'=>$company->id,'user_id'=>$request->user()->id]);
        return view('notifications.index',compact('company','notifications','preference'));
    }
    public function read(Request $request, CompanyNotification $notification): RedirectResponse
    {
        $this->assertRecipient($request,$notification);
        $notification->update(['read_at_utc'=>$notification->read_at_utc?:now('UTC')]);
        return back();
    }
    public function open(Request $request, CompanyNotification $notification, NotificationAuthorization $authorization): RedirectResponse
    {
        $this->assertRecipient($request,$notification);
        abort_unless($authorization->allowed($notification),403);
        $notification->update(['read_at_utc'=>$notification->read_at_utc?:now('UTC'),'source_seen_at_utc'=>$notification->source_seen_at_utc?:now('UTC')]);
        return redirect($notification->deep_link_path);
    }
    public function preferences(Request $request): RedirectResponse
    {
        $company=$request->attributes->get('currentCompany');
        $data=$request->validate(['push_enabled'=>['nullable','boolean'],'email_enabled'=>['nullable','boolean'],'email_fallback_enabled'=>['nullable','boolean'],'quiet_starts_at'=>['nullable','date_format:H:i'],'quiet_ends_at'=>['nullable','date_format:H:i']]);
        if(!$request->user()->email_verified_at){$data['email_enabled']=false;$data['email_fallback_enabled']=false;}
        UserNotificationPreference::query()->updateOrCreate(['organization_id'=>$company->id,'user_id'=>$request->user()->id],[
            'in_app_enabled'=>true,'push_enabled'=>(bool)($data['push_enabled']??false),'email_enabled'=>(bool)($data['email_enabled']??false),
            'email_fallback_enabled'=>(bool)($data['email_fallback_enabled']??false),'quiet_starts_at'=>$data['quiet_starts_at']??null,'quiet_ends_at'=>$data['quiet_ends_at']??null]);
        return back()->with('status','通知設定を保存しました。');
    }
    private function assertRecipient(Request $request, CompanyNotification $notification): void
    {
        abort_unless($notification->organization_id===$request->attributes->get('currentCompany')->id&&$notification->recipient_user_id===$request->user()->id,404);
    }
}
