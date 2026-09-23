<?php

namespace App\AI\Data;

use InvalidArgumentException;

final readonly class ModelRequest
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $structuredOutputSchema
     */
    public function __construct(
        public string $prompt,
        public string $instructions = '',
        public array $context = [],
        public ?string $provider = null,
        public ?string $model = null,
        public ?int $timeout = null,
        public ?array $structuredOutputSchema = null,
        public ?string $correlationId = null,
    ) {
        if ($this->prompt === '') {
            throw new InvalidArgumentException('A model request prompt is required.');
        }

        if ($this->timeout !== null && $this->timeout < 1) {
            throw new InvalidArgumentException('A model request timeout must be at least one second.');
        }
    }

    public function requiresStructuredOutput(): bool
    {
        return $this->structuredOutputSchema !== null;
    }
}
