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

    'line' => [
        'channel_secret' => env('LINE_CHANNEL_SECRET'),
        'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN'),
        'reply_url' => env('LINE_REPLY_URL', 'https://api.line.me/v2/bot/message/reply'),
        'push_url' => env('LINE_PUSH_URL', 'https://api.line.me/v2/bot/message/push'),
        'reply_token_safe_window_seconds' => (int) env('LINE_REPLY_TOKEN_SAFE_WINDOW_SECONDS', 45),
        'schedule_image_disk' => env('LINE_SCHEDULE_IMAGE_DISK', 's3'),
        'schedule_image_font' => env('LINE_SCHEDULE_IMAGE_FONT', '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'),
        'schedule_image_retention_days' => (int) env('LINE_SCHEDULE_IMAGE_RETENTION_DAYS', 7),
    ],

    'bo3' => [
        'base_url' => env('BO3_BASE_URL', 'https://bo3.gg'),
        'api_url' => env('BO3_API_URL', 'https://api.bo3.gg/api/v1'),
        'cache_seconds' => (int) env('BO3_CACHE_SECONDS', 300),
        'h2h_cache_seconds' => (int) env('BO3_H2H_CACHE_SECONDS', 300),
        'timeout_seconds' => (int) env('BO3_TIMEOUT_SECONDS', 10),
        'timezone' => env('BO3_TIMEZONE', 'Asia/Taipei'),
    ],

    'odds' => [
        'api_key' => env('ODDS_API_KEY'),
        'base_url' => env('ODDS_API_BASE_URL', 'https://api.odds-api.io/v3'),
        'bookmakers' => env('ODDS_API_BOOKMAKERS'),
        'bookmaker_priority' => env('ODDS_API_BOOKMAKER_PRIORITY', 'Stake,Bet365'),
        'cache_seconds' => (int) env('ODDS_API_CACHE_SECONDS', 60),
        'timeout_seconds' => (int) env('ODDS_API_TIMEOUT_SECONDS', 10),
    ],

];
