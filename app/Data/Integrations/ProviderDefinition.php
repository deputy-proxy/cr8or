<?php

namespace App\Data\Integrations;

final readonly class ProviderDefinition
{
    /** @param list<string> $operations */
    public function __construct(
        public string $key,
        public string $integration,
        public string $name,
        public array $operations = [],
    ) {}
}