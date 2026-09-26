<?php
namespace App\Services\Notification;
use App\Models\CompanyNotification;
use App\Models\Capture;
use App\Models\OrganizationUser;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotificationPreference;
class NotificationSourceWriter
{
    public function __construct(private readonly NotificationTiming $timing){}
    public function actionAssigned(User $actor,Task $action,string $timing='now',?string $specifiedAt=null): ?CompanyNotification
    {
        if(!$action->assigned_to||$action->assigned_to===$actor->id)return null;
        return $this->record($action,$action->assigned_to,$actor,CompanyNotification::TYPE_ACTION_ASSIGNED,'Actionが割り当てられました',$action->title,'action.created',$timing,$specifiedAt);
    }
    public function reviewAttention(User $actor,Task $action): ?CompanyNotification
    {
        if(!$action->reviewer_user_id||$action->reviewer_user_id===$actor->id)return null;
        return $this->record($action,$action->reviewer_user_id,$actor,CompanyNotification::TYPE_REVIEW_ATTENTION,'確認依頼が届きました',$action->title,'action.complete');
    }
    public function actionReturned(User $actor,Task $action): ?CompanyNotification
    {
        if(!$action->assigned_to||$action->assigned_to===$actor->id)return null;
        return $this->record($action,$action->assigned_to,$actor,CompanyNotification::TYPE_ACTION_RETURNED,'Actionが差し戻されました',$action->title,'action.reject');
    }
    public function markActionDone(Task $action, User $recipient, string $notificationType): void
    {
        CompanyNotification::query()
            ->where('organization_id', $action->organization_id)
            ->where('recipient_user_id', $recipient->id)
            ->where('source_type', 'Task')
            ->where('source_id', $action->id)
            ->where('type', $notificationType)
            ->whereNull('action_done_at_utc')
            ->update(['action_done_at_utc' => now('UTC')]);
    }
    public function todayDigest(int $organizationId,User $recipient,int $itemCount,string $localDate): ?CompanyNotification
    {
        if($itemCount<1)return null;
        $body='確認する項目が'.$itemCount.'件あります。';
        $dedupe='today-digest:'.$organizationId.':'.$recipient->id.':'.$localDate;
        return $this->recordRaw($organizationId,$recipient->id,null,CompanyNotification::TYPE_TODAY_DIGEST,'今日のActionがあります',$body,'/company/today',$dedupe,'today.digest','next_window');
    }
    public function captureCreated(User $actor,Capture $capture): ?CompanyNotification
    {
        $type=match($capture->type){Capture::TYPE_SELF=>CompanyNotification::TYPE_CAPTURE_REMINDER,Capture::TYPE_REQUEST=>CompanyNotification::TYPE_CAPTURE_REQUEST,default=>CompanyNotification::TYPE_CAPTURE_TELL_LATER};
        $title=$capture->type===Capture::TYPE_SELF?'預けた内容の確認時刻です':'新しいCaptureがあります';
        $specified=$capture->notify_at_utc?->timezone(config('app.timezone'))->format('Y-m-d H:i');
        return $this->recordRaw($capture->organization_id,$capture->recipient_user_id,$actor,$type,$title,'Company OSを開いて内容を確認してください。','/company/captures/'.$capture->public_id,'capture:'.$capture->id.':created','capture.created',$capture->notification_timing,'Capture',$capture->id,$specified);
    }
    private function record(Task $action,int $recipientId,User $actor,string $type,string $title,string $body,string $event,string $timing='now',?string $specifiedAt=null): ?CompanyNotification
    {
        $path='/company/projects/'.$action->project_id.'#action-'.$action->id;
        $dedupe=$type.':task:'.$action->id.':'.$event.':'.($action->updated_at?->getTimestamp()??now()->getTimestamp());
        return $this->recordRaw($action->organization_id,$recipientId,$actor,$type,$title,$body,$path,$dedupe,$event,$timing,'Task',$action->id,$specifiedAt);
    }
    private function recordRaw(int $organizationId,int $recipientId,?User $actor,string $type,string $title,string $body,string $path,string $dedupeKey,string $event,string $timing,string $sourceType='Digest',?int $sourceId=null,?string $specifiedAt=null): ?CompanyNotification
    {
        $recipient=User::query()->find($recipientId);
        $membership=OrganizationUser::query()->where('organization_id',$organizationId)->where('user_id',$recipientId)->where('membership_status',OrganizationUser::STATUS_ACTIVE)->first();
        if(!$recipient?->is_active||!$membership)return null;
        $eligible=$this->timing->eligibleAt($organizationId,$timing,$specifiedAt,$recipientId);
        // The existing schema requires a timestamp. When a confirmed policy has
        // no available window, this is only a recheck point; visibility and the
        // processor still fail closed against the current policy.
        $scheduledAt=$eligible??now('UTC')->addMinutes(5);
        $notification=CompanyNotification::query()->firstOrCreate(['dedupe_key'=>$dedupeKey],[
            'organization_id'=>$organizationId,'recipient_user_id'=>$recipientId,'actor_user_id'=>$actor?->id,'type'=>$type,'source_type'=>$sourceType,
            'source_id'=>$sourceId,'source_event'=>$event,'title'=>$title,'body'=>$body,'deep_link_path'=>$path,'timing'=>$timing,
            'content_visible_at_utc'=>$scheduledAt,'eligible_at_utc'=>$scheduledAt,'membership_access_epoch'=>$membership->access_epoch,'credential_generation'=>$recipient->credential_generation]);
        if(!$notification->wasRecentlyCreated)return $notification;
        $preference=UserNotificationPreference::query()->where('organization_id',$organizationId)->where('user_id',$recipientId)->first();
        $channels=['in_app'];if($preference?->push_enabled)$channels[]='push';if($preference?->email_enabled&&$recipient->email_verified_at)$channels[]='email';
        foreach($channels as $channel)$notification->deliveries()->create(['channel'=>$channel,'status'=>'pending','available_at_utc'=>$scheduledAt]);
        return $notification;
    }
}
