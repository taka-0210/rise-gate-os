<?php
return [
    'delivery_enabled' => env('COMPANY_NOTIFICATION_DELIVERY_ENABLED', false),
    'max_batch' => (int) env('COMPANY_NOTIFICATION_MAX_BATCH', 100),
    'max_attempts' => (int) env('COMPANY_NOTIFICATION_MAX_ATTEMPTS', 3),
    'vapid' => [
        'subject' => env('WEBPUSH_VAPID_SUBJECT'),
        'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
    ],
];
