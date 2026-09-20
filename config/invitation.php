<?php

return [
    'expires_days' => (int) env('STAFF_INVITATION_EXPIRES_DAYS', 7),
    'claim_minutes' => (int) env('STAFF_INVITATION_CLAIM_MINUTES', 30),
    'resend_cooldown_seconds' => (int) env('STAFF_INVITATION_RESEND_COOLDOWN_SECONDS', 60),
    'max_requests_per_minute' => (int) env('STAFF_INVITATION_MAX_REQUESTS_PER_MINUTE', 10),
    'avatar' => [
        'max_kilobytes' => (int) env('USER_AVATAR_MAX_KILOBYTES', 5120),
        'max_pixels' => (int) env('USER_AVATAR_MAX_PIXELS', 16000000),
        'max_dimension' => (int) env('USER_AVATAR_MAX_DIMENSION', 512),
    ],
];
