<?php

return [
    'environment' => env('APP_ENV', 'production'),

    'defaults' => [
        'enabled' => true,
        'max_steps' => 5,
        'max_retries' => 3,
        'timeout_seconds' => 120,
        'max_context_bytes' => 120000,
        'retrieved_knowledge_limit' => 5,
        'memory_limit' => 20,
        'provider' => env('CR8OR_AI_PROVIDER', 'openai'),
        'model' => null,
        'fallback_providers' => [],
    ],
];