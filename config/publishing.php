<?php

return [
    'linkedin' => [
        'api_version' => env('SOCIAL_LINKEDIN_API_VERSION', '202609'),
    ],
    'adapters' => [
        'linkedin' => ['key' => 'linkedin', 'class' => \App\Services\Publishing\LinkedInPublishingAdapter::class, 'enabled' => false, 'native_scheduling' => false],
        'facebook' => ['key' => 'facebook', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'instagram' => ['key' => 'instagram', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'tiktok' => ['key' => 'tiktok', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'bsky' => ['key' => 'bsky', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'youtube' => ['key' => 'youtube', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'threads' => ['key' => 'threads', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
        'x' => ['key' => 'x', 'class' => null, 'enabled' => false, 'native_scheduling' => false],
    ],
];