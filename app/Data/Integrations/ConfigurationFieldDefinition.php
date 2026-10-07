<?php

namespace App\Data\Integrations;

final readonly class ConfigurationFieldDefinition
{
    /**
     * @param  array<string, string>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public bool $required = false,
        public array $options = [],
        public bool $sensitive = false,
    ) {}
}
