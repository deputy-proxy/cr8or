<?php

namespace App\Data\Integrations;

final readonly class IntegrationDefinition
{
    public function __construct(
        public string $key,
        public string $name,
        public string $purpose,
    ) {}
}