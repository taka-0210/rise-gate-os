<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-5.6-terra'),
        'input_usd_per_million' => env('OPENAI_INPUT_USD_PER_MILLION', 2.50),
        'output_usd_per_million' => env('OPENAI_OUTPUT_USD_PER_MILLION', 15.00),
    ],

    'ai' => [
        // Operational kill switch: keeps proposals/history readable while stopping new Apply operations.
        'scope_one_apply_enabled' => env('AI_SCOPE_ONE_APPLY_ENABLED', true),
        'scope_one_context_max_entities' => env('AI_SCOPE_ONE_CONTEXT_MAX_ENTITIES', 500),
        'scope_one_context_max_chars' => env('AI_SCOPE_ONE_CONTEXT_MAX_CHARS', 100000),
    ],

];
