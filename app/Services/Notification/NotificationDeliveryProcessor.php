<?php
namespace App\Services\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDeliveryAttempt;
use App\Models\OrganizationNotificationPolicy;
use App\Models\PushSubscription;
use App\Models\UserNotificationPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
class NotificationDeliveryProcessor
{
    public function __construct(private readonly NotificationAuthorization $authorization,private readonly WebPushTransport $push){}
    public function run(int $requestedLimit): array
    {
        if(!config('company_notifications.delivery_enabled'))return ['processed'=>0,'delivered'=>0,'failed'=>0,'disabled'=>true];
        $limit=max(1,min($requestedLimit,(int)config('company_notifications.max_batch',100),200));
        $ids=NotificationDelivery::query()->where('status','pending')->where('available_at_utc','<=',now('UTC'))
            ->where(fn($q)=>$q->whereNull('leased_until_utc')->orWhere('leased_until_utc','<',now('UTC')))->orderBy('id')->limit($limit)->pluck('id');
        $result=['processed'=>0,'delivered'=>0,'failed'=>0,'disabled'=>false];
        foreach($ids as $id){$result['processed']++;$this->process((int)$id)?$result['delivered']++:$result['failed']++;}
        return $result;
    }
    private function process(int $id): bool
    {
        $token=(string)Str::uuid();
        $delivery=DB::transaction(function()use($id,$token){
            $row=NotificationDelivery::query()->lockForUpdate()->find($id);
            if(!$row||$row->status!=='pending'||($row->leased_until_utc&&$row->leased_until_utc->isFuture()))return null;
            $row->update(['lease_token'=>$token,'leased_until_utc'=>now('UTC')->addMinutes(5),'attempt_count'=>$row->attempt_count+1]);
            return $row->fresh(['notification.recipient']);
        },3);
        if(!$delivery)return false;
        $outcome='failed';$reason=null;$receipt=null;
        try{
            $notification=$delivery->notification;
            if(!$this->authorization->allowed($notification)){$outcome='cancelled';$reason='authorization_revoked';}
            else{
                $preference=UserNotificationPreference::query()->where('organization_id',$notification->organization_id)->where('user_id',$notification->recipient_user_id)->first();
                $policy=OrganizationNotificationPolicy::query()->where('organization_id',$notification->organization_id)->first();
                if($delivery->channel==='in_app'){$outcome='delivered';}
                elseif(!$policy?->is_confirmed){$outcome='cancelled';$reason='organization_policy_unconfirmed';}
                elseif($delivery->channel==='push'&&!$preference?->push_enabled){$outcome='cancelled';$reason='channel_disabled';}
                elseif($delivery->channel==='email'&&(!$preference?->email_enabled||!$notification->recipient->email_verified_at)){$outcome='cancelled';$reason='channel_disabled';}
                elseif($delivery->channel==='push'){
                    $subscriptions=PushSubscription::query()->where('organization_id',$notification->organization_id)->where('user_id',$notification->recipient_user_id)->whereNull('revoked_at_utc')->get();
                    if($subscriptions->isEmpty())throw new \RuntimeException('no_active_subscription');
                    $opaqueLink=route('notifications.open',$notification);
                    foreach($subscriptions as $subscription)$receipt=$this->push->send($subscription,$opaqueLink);
                    $outcome='delivered';
                }else{
                    Mail::raw('Company OSに新しい通知があります。ログインして内容を確認してください。',fn($mail)=>$mail->to($notification->recipient->email)->subject('Company OS 通知'));
                    $receipt=hash('sha256',$notification->recipient_user_id.':'.$notification->id.':email');$outcome='delivered';
                }
            }
        }catch(Throwable $e){$reason=Str::limit($e->getMessage(),80,'');}
        NotificationDeliveryAttempt::query()->create(['notification_delivery_id'=>$delivery->id,'operation_id'=>(string)Str::uuid(),'outcome'=>$outcome,'reason_code'=>$reason,'provider_receipt_hash'=>$receipt,'attempted_at_utc'=>now('UTC')]);
        $max=(int)config('company_notifications.max_attempts',3);
        $status=$outcome==='delivered'?'delivered':($outcome==='cancelled'?'cancelled':($delivery->attempt_count>=$max?'failed':'pending'));
        $delivery->update(['status'=>$status,'delivered_at_utc'=>$outcome==='delivered'?now('UTC'):null,'last_error_code'=>$reason,
            'available_at_utc'=>$status==='pending'?now('UTC')->addMinutes(5*$delivery->attempt_count):$delivery->available_at_utc,'lease_token'=>null,'leased_until_utc'=>null]);
        if($delivery->channel==='push'&&$status==='failed'){
            $preference=UserNotificationPreference::query()->where('organization_id',$delivery->notification->organization_id)->where('user_id',$delivery->notification->recipient_user_id)->first();
            if($preference?->email_fallback_enabled&&$delivery->notification->recipient->email_verified_at){
                NotificationDelivery::query()->firstOrCreate(['company_notification_id'=>$delivery->company_notification_id,'channel'=>'email'],['status'=>'pending','available_at_utc'=>now('UTC')]);
            }
        }
        return $outcome==='delivered';
    }
}
