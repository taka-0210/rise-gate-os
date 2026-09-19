<?php

return [
    'login' => [
        'max_attempts_per_minute' => (int) env('ACCOUNT_LOGIN_MAX_ATTEMPTS_PER_MINUTE', 5),
        'max_attempts_per_ip_per_minute' => (int) env('ACCOUNT_LOGIN_MAX_ATTEMPTS_PER_IP_PER_MINUTE', 30),
        'decay_seconds' => (int) env('ACCOUNT_LOGIN_DECAY_SECONDS', 60),
    ],
    'mail' => [
        'mailer' => env('ACCOUNT_MAIL_MAILER'),
        'max_per_minute' => (int) env('ACCOUNT_MAIL_MAX_PER_MINUTE', 1),
        'max_per_hour' => (int) env('ACCOUNT_MAIL_MAX_PER_HOUR', 5),
    ],
    'token' => [
        'email_expire_minutes' => (int) env('ACCOUNT_EMAIL_TOKEN_EXPIRE_MINUTES', 60),
        'max_attempts_per_minute' => (int) env('ACCOUNT_TOKEN_MAX_ATTEMPTS_PER_MINUTE', 10),
    ],
];
