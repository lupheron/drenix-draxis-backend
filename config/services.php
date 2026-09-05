<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
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

    'whapi' => [
        'token' => env('WHAPI_TOKEN'),
        'base_url' => rtrim(env('WHAPI_BASE_URL', 'https://gate.whapi.cloud'), '/'),
    ],

    'telegram' => [
        // Prefer TG_GATEWAY_TOKEN; TG_BOT_TOKEN kept as legacy alias for same Gateway token
        'gateway_token' => env('TG_GATEWAY_TOKEN', env('TG_BOT_TOKEN')),
        'bot_token' => env('TG_BOT_TOKEN'),
        'api_id' => env('TG_API_ID'),
        'api_hash' => env('TG_API_HASH'),
        'lookup_key' => env('TG_LOOKUP_KEY'),
        'session_file' => env('TG_SESSION_FILE'),
    ],

];
