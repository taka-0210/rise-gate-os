<?php
namespace App\Services\Notification;
use App\Models\PushSubscription;
use RuntimeException;
class WebPushTransport
{
    public function send(PushSubscription $subscription, string $deepLink): string
    {
        $vapid=config('company_notifications.vapid');
        if(!class_exists(\Minishlink\WebPush\WebPush::class)||blank($vapid['subject'])||blank($vapid['public_key'])||blank($vapid['private_key'])){
            throw new RuntimeException('web_push_not_configured');
        }
        $webPush=new \Minishlink\WebPush\WebPush(['VAPID'=>['subject'=>$vapid['subject'],'publicKey'=>$vapid['public_key'],'privateKey'=>$vapid['private_key']]]);
        $target=\Minishlink\WebPush\Subscription::create(['endpoint'=>$subscription->endpoint_encrypted,'publicKey'=>$subscription->p256dh_encrypted,'authToken'=>$subscription->auth_encrypted]);
        $report=$webPush->sendOneNotification($target,json_encode(['title'=>'Company OSに新しい通知があります','url'=>$deepLink],JSON_UNESCAPED_UNICODE));
        if(!$report->isSuccess())throw new RuntimeException('web_push_rejected');
        return hash('sha256',(string)$report->getRequest()->getUri());
    }
}
