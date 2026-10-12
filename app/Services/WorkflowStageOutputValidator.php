<?php

namespace App\Services;

use App\Models\WorkflowStage;
use Illuminate\Validation\ValidationException;

final class WorkflowStageOutputValidator
{
    /** @param array<string, mixed> $output */
    public function validate(WorkflowStage $stage, array $output): void
    {
        $contractValue = $stage->getAttribute('output_contract');
        $contract = is_array($contractValue) ? $contractValue : [];

        if ($contract === []) {
            return;
        }

        $errors = [];
        $isSchema = array_key_exists('required', $contract)
            || array_key_exists('properties', $contract)
            || array_key_exists('type', $contract)
            || array_key_exists('items', $contract);

        if ($isSchema) {
            $this->validateSchema($contract, $output, '', $errors);
        } else {
            // Capability output contracts use field => "type|required" definitions.
            foreach ($contract as $field => $definition) {
                if (! is_string($field) || $field === '') {
                    continue;
                }

                if (is_string($definition)) {
                    $parts = explode('|', $definition);
                    $type = $parts[0];
                    $flags = array_slice($parts, 1);
                    $required = in_array('required', $flags, true);
                    $nullable = in_array('nullable', $flags, true) || in_array('null', $flags, true);

                    if (! array_key_exists($field, $output)) {
                        if ($required) {
                            $errors[$field] = "Workflow stage [{$stage->key}] output is missing required field [{$field}].";
                        }

                        continue;
                    }

                    if ($output[$field] === null && $nullable) {
                        continue;
                    }

                    if (! $this->matchesType($output[$field], $type)) {
                        $errors[$field] = "Workflow stage [{$stage->key}] output field [{$field}] must be of type [{$type}].";
                    }

                    continue;
                }

                if (is_array($definition) && array_key_exists($field, $output)) {
                    $this->validateSchema($definition, $output[$field], $field, $errors);
                }
            }
        }

        if ($errors !== []) {
            $messages = [];
            foreach ($errors as $path => $message) {
                $messages["workflow.{$stage->key}.output".($path === '' ? '' : ".{$path}")] = $message;
            }

            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $value
     * @param  array<string, string>  $errors
     */
    private function validateSchema(array $schema, array $value, string $prefix, array &$errors): void
    {
        foreach ($this->stringList($schema['required'] ?? []) as $field) {
            if (! array_key_exists($field, $value)) {
                $path = $this->joinPath($prefix, $field);
                $errors[$path] = "Required output field [{$path}] is missing.";
            }
        }

        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        foreach ($properties as $field => $propertySchema) {
            if (! is_string($field) || ! array_key_exists($field, $value)) {
                continue;
            }

            $path = $this->joinPath($prefix, $field);
            if (is_array($propertySchema)) {
                $this->validateValue($propertySchema, $value[$field], $path, $errors);
            }
        }

        if (isset($schema['type']) && ! $this->matchesType($value, $schema['type'])) {
            $path = $prefix === '' ? 'value' : $prefix;
            $errors[$path] = sprintf('Output field [%s] must be of the declared type [%s].', $path, $this->typeName($schema['type']));
        }
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, string>  $errors
     */
    private function validateValue(array $schema, mixed $value, string $path, array &$errors): void
    {
        if (isset($schema['type']) && ! (($schema['nullable'] ?? false) === true && $value === null) && ! $this->matchesType($value, $schema['type'])) {
            $errors[$path] = sprintf('Output field [%s] must be of the declared type [%s].', $path, $this->typeName($schema['type']));

            return;
        }

        if (is_array($value)) {
            $this->validateSchema($schema, $value, $path, $errors);
        }

        if (isset($schema['items']) && is_array($schema['items']) && is_array($value)) {
            foreach ($value as $index => $item) {
                $this->validateValue($schema['items'], $item, $path.'.'.$index, $errors);
            }
        }
    }

    private function matchesType(mixed $value, mixed $type): bool
    {
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if (is_string($candidate) && $this->matchesType($value, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return match ($type) {
            'mixed', null => true,
            'string' => is_string($value),
            'integer', 'int' => is_int($value),
            'number', 'numeric' => is_int($value) || is_float($value),
            'boolean', 'bool' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'null' => $value === null,
            // Named Capability result types such as content-item or knowledge-retrieval
            // are semantic aliases whose payloads are structured arrays, not JSON Schema primitives.
            default => is_array($value),
        };
    }

    private function typeName(mixed $type): string
    {
        return is_array($type) ? implode('|', array_map('strval', $type)) : (string) $type;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    private function joinPath(string $prefix, string $field): string
    {
        return $prefix === '' ? $field : $prefix.'.'.$field;
    }
}
