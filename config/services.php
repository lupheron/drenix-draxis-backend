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

    'whapi' => [
        'token' => env('WHAPI_TOKEN'),
    ],

    'telegram' => [
        'api_id' => env('TG_API_ID'),
        'api_hash' => env('TG_API_HASH'),
        // Telegram Gateway API token (gateway.telegram.org) — NOT a BotFather bot token
        'bot_token' => env('TG_BOT_TOKEN'),
        // Optional CheckNumber.ai (or similar) key
        'lookup_key' => env('TG_LOOKUP_KEY'),
        // Optional MadelineProto session file for ImportContacts (MTProto user session)
        'session_file' => env('TG_SESSION_FILE'),
    ],

];
