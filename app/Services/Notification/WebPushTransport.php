<?php

namespace App\Services\Notification;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use RuntimeException;

class WebPushTransport
{
    public function send(PushSubscription $subscription, string $deepLink): string
    {
        $vapid = config('company_notifications.vapid');
        if (! class_exists(WebPush::class) || blank($vapid['subject']) || blank($vapid['public_key']) || blank($vapid['private_key'])) {
            throw new RuntimeException('web_push_not_configured');
        }
        $webPush = new WebPush(['VAPID' => ['subject' => $vapid['subject'], 'publicKey' => $vapid['public_key'], 'privateKey' => $vapid['private_key']]]);
        $target = Subscription::create(['endpoint' => $subscription->endpoint_encrypted, 'publicKey' => $subscription->p256dh_encrypted, 'authToken' => $subscription->auth_encrypted]);
        $report = $webPush->sendOneNotification($target, json_encode(['title' => 'Company OSに新しい通知があります', 'url' => $deepLink], JSON_UNESCAPED_UNICODE));
        if (! $report->isSuccess()) {
            $status = $report->getResponse()?->getStatusCode();
            throw new RuntimeException('web_push_rejected_'.($status ?? 'no_response'));
        }

        return hash('sha256', (string) $report->getRequest()->getUri());
    }
}
