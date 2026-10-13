<?php

return [
    'linkedin' => [
        'api_version' => env('SOCIAL_LINKEDIN_API_VERSION', '202609'),
    ],
    'status_reconciliation' => [
        'initial_delay_seconds' => (int) env('PUBLISHING_STATUS_INITIAL_DELAY_SECONDS', 60),
        'poll_delay_seconds' => (int) env('PUBLISHING_STATUS_POLL_DELAY_SECONDS', 300),
        'max_polls' => (int) env('PUBLISHING_STATUS_MAX_POLLS', 48),
        'queue_connection' => env('PUBLISHING_STATUS_QUEUE_CONNECTION', 'database'),
    ],
    'youtube' => [
        'upload_timeout' => (int) env('SOCIAL_YOUTUBE_UPLOAD_TIMEOUT', 120),
        'chunk_size_bytes' => (int) env('SOCIAL_YOUTUBE_UPLOAD_CHUNK_SIZE', 8388608),
        'max_upload_bytes' => (int) env('SOCIAL_YOUTUBE_MAX_UPLOAD_BYTES', 256000000000),
        'default_privacy_status' => env('SOCIAL_YOUTUBE_DEFAULT_PRIVACY_STATUS', 'private'),
        'default_category_id' => env('SOCIAL_YOUTUBE_DEFAULT_CATEGORY_ID') ?: null,
        'notify_subscribers' => env('SOCIAL_YOUTUBE_NOTIFY_SUBSCRIBERS', false),
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
        'bsky' => ['key' => 'bsky', 'class' => \App\Services\Publishing\BlueskyPublishingAdapter::class, 'enabled' => false, 'native_scheduling' => false],
        'youtube' => ['key' => 'youtube', 'class' => \App\Services\Publishing\YouTubePublishingAdapter::class, 'enabled' => false, 'native_scheduling' => false],
        'threads' => ['key' => 'threads', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'x' => ['key' => 'x', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
    ],
];