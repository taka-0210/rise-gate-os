<?php

return [
    'webhook' => [
        'enabled' => env('POSTMARK_WEBHOOK_ENABLED', false),
        'user' => env('POSTMARK_WEBHOOK_BASIC_USER'),
        'password' => env('POSTMARK_WEBHOOK_BASIC_PASSWORD'),
        'allowed_cidrs' => array_filter(explode(',', (string) env('POSTMARK_WEBHOOK_ALLOWED_CIDRS', ''))),
        'stream' => env('POSTMARK_MESSAGE_STREAM_ID'),
        'server_id' => env('POSTMARK_SERVER_ID'),
    ],
];
