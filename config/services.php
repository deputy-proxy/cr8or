<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional file to locate the various service credentials.
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

    'canva' => [
        'url' => env('CANVA_API_URL', 'https://api.canva.com/rest/v1'),
        'timeout' => (int) env('CANVA_TIMEOUT', 15),
        'credentials' => [
            'default' => env('CANVA_ACCESS_TOKEN'),
        ],
    ],

    'postiz' => [
        'url' => env('POSTIZ_API_URL', 'https://api.postiz.com/public/v1'),
        'timeout' => (int) env('POSTIZ_TIMEOUT', 15),
        'key' => env('POSTIZ_API_KEY'),
    ],

    'integrations' => [
        'webhooks' => [
            'default_secret' => env('CR8OR_WEBHOOK_SECRET'),
            'secrets' => [
                'canva' => env('CANVA_WEBHOOK_SECRET'),
                'postiz' => env('POSTIZ_WEBHOOK_SECRET'),
                'cr8or-media' => env('CR8OR_MEDIA_WEBHOOK_SECRET'),
                'github' => env('GITHUB_WEBHOOK_SECRET'),
            ],
        ],
    ],

    'command_webhooks' => [
        'tolerance' => (int) env('CR8OR_COMMAND_WEBHOOK_TOLERANCE', 300),
        'credentials' => json_decode((string) env('CR8OR_COMMAND_WEBHOOK_CREDENTIALS', '{}'), true) ?: [],
    ],

];