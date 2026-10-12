<?php

return [
    'linkedin' => [
        'api_version' => env('SOCIAL_LINKEDIN_API_VERSION', '202609'),
    ],
    'instagram' => [
        'container_poll_attempts' => (int) env('SOCIAL_INSTAGRAM_CONTAINER_POLL_ATTEMPTS', 5),
        'container_poll_interval_ms' => (int) env('SOCIAL_INSTAGRAM_CONTAINER_POLL_INTERVAL_MS', 250),
    ],
    'tiktok' => [
        'upload_timeout' => (int) env('SOCIAL_TIKTOK_UPLOAD_TIMEOUT', 120),
    ],
    'adapters' => [
        'linkedin' => ['key' => 'linkedin', 'class' => \App\Services\Publishing\LinkedInPublishingAdapter::class, 'enabled' => false, 'native_scheduling' => false],
        'facebook' => ['key' => 'facebook', 'class' => \App\Services\Publishing\FacebookPublishingAdapter::class, 'enabled' => false, 'native_scheduling' => false],
        'instagram' => ['key' => 'instagram', 'class' => \App\Services\Publishing\InstagramPublishingAdapter::class, 'enabled' => false, 'native_scheduling' => false],
        'tiktok' => ['key' => 'tiktok', 'class' => \App\Services\Publishing\TikTokPublishingAdapter::class, 'enabled' => false, 'native_scheduling' => false],
        'bsky' => ['key' => 'bsky', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'youtube' => ['key' => 'youtube', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'threads' => ['key' => 'threads', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'x' => ['key' => 'x', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
    ],
];