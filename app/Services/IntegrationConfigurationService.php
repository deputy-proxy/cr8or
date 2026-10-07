<?php

namespace App\Services;

use App\Data\Integrations\ConfigurationFieldDefinition;
use Illuminate\Support\Facades\Validator;
use LogicException;

final class IntegrationConfigurationService
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>|null  $configuration
     * @return array<string, mixed>|null
     */
    public function validate(?string $provider, ?array $configuration): ?array
    {
        if ($provider === null || trim($provider) === '') {
            return $configuration;
        }

        if (! $this->registry->hasProvider($provider)) {
            if ($configuration === null) {
                return null;
            }

            throw new LogicException("Unknown integration provider [{$provider}].");
        }

        $fields = $this->registry->provider($provider)->configurationFields;

        if ($configuration === null && $fields === []) {
            return null;
        }

        $configuration ??= [];

        $unknown = array_diff(array_keys($configuration), array_map(
            static fn (ConfigurationFieldDefinition $field): string => $field->key,
            $fields,
        ));

        if ($unknown !== []) {
            throw new LogicException(sprintf(
                'Integration provider [%s] does not support configuration fields [%s].',
                $provider,
                implode(', ', $unknown),
            ));
        }

        $rules = [];

        foreach ($fields as $field) {
            $rule = $field->required ? ['required'] : ['nullable'];

            $rule[] = match ($field->type) {
                'url', 'text', 'select' => 'string',
                'boolean' => 'boolean',
                'integer' => 'integer',
                'array' => 'array',
                default => throw new LogicException("Unsupported integration configuration field type [{$field->type}]."),
            };

            if ($field->type === 'url') {
                $rule[] = 'url';
            }

            if ($field->options !== []) {
                $rule[] = 'in:'.implode(',', array_keys($field->options));
            }

            $rules[$field->key] = $rule;
        }

        $validated = Validator::make($configuration, $rules)->validate();

        foreach ($fields as $field) {
            if ($field->sensitive && array_key_exists($field->key, $validated)) {
                throw new LogicException("Integration configuration field [{$field->key}] cannot contain credential material.");
            }
        }

        return $validated;
    }
}
