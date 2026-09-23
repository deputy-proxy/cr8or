<?php

namespace App\AI\Data;

final readonly class ModelResult
{
    /**
     * @param  array<string, mixed>|null  $structured
     * @param  array<string, int>  $usage
     */
    public function __construct(
        public string $text,
        public ?array $structured,
        public string $provider,
        public string $model,
        public string $invocationId,
        public array $usage = [],
        public ?string $correlationId = null,
    ) {}
}