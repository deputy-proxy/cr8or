<?php

namespace App\AI;

use InvalidArgumentException;

final class ReasoningOutputValidator
{
    /** @return array<string, mixed> */
    public static function agentSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['answer', 'decision_title', 'decision_summary', 'decision_rationale', 'termination', 'capability_requests'],
            'properties' => [
                'answer' => ['type' => 'string'],
                'decision_title' => ['type' => 'string'],
                'decision_summary' => ['type' => 'string'],
                'decision_rationale' => ['type' => 'string'],
                'evidence_references' => ['type' => 'array', 'items' => ['type' => 'string']],
                'selected_experts' => ['type' => 'array', 'items' => ['type' => 'string']],
                'capability_requests' => ['type' => 'array'],
                'delegation_requests' => ['type' => 'array'],
                'memory' => [
                    'type' => 'object',
                    'properties' => [
                        'episodic' => ['type' => 'array'],
                        'semantic' => ['type' => 'array'],
                    ],
                ],
                'termination' => [
                    'type' => 'string',
                    'enum' => ['continue', 'completed', 'waiting_for_input', 'waiting_for_approval', 'delegated', 'paused'],
                ],
                'termination_reason' => ['type' => 'string'],
                'next_step' => ['type' => 'string'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function expertSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['answer'],
            'properties' => [
                'answer' => ['type' => 'string'],
                'analysis' => ['type' => 'string'],
                'recommendations' => ['type' => 'array'],
                'evidence_references' => ['type' => 'array', 'items' => ['type' => 'string']],
                'missing_information' => ['type' => 'array', 'items' => ['type' => 'string']],
                'capability_requests' => ['type' => 'array'],
                'uncertainty' => [
                    'type' => 'object',
                    'required' => ['level', 'notes'],
                    'properties' => [
                        'level' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        'notes' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function normalizeAgent(array $output): array
    {
        $output['answer'] ??= '';
        $output['decision_title'] ??= '';
        $output['decision_summary'] ??= '';
        $output['decision_rationale'] ??= '';
        $output['evidence_references'] ??= [];
        $output['selected_experts'] ??= [];
        $output['capability_requests'] ??= [];
        $output['delegation_requests'] ??= [];
        $output['memory'] ??= [];
        $output['termination'] ??= 'completed';
        $output['termination_reason'] ??= '';
        $output['next_step'] ??= '';

        return self::validate($output, self::agentSchema());
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    public static function normalizeExpert(array $output): array
    {
        $output['answer'] ??= json_encode($output, JSON_THROW_ON_ERROR);
        $output['analysis'] ??= is_string($output['answer'] ?? null) ? $output['answer'] : '';
        $output['recommendations'] ??= [];
        $output['evidence_references'] ??= [];
        $output['missing_information'] ??= [];
        $output['uncertainty'] ??= [
            'level' => 'medium',
            'notes' => 'Reasoning is bounded by the authorized execution context.',
        ];
        $output['capability_requests'] ??= [];

        return self::validate($output, self::expertSchema());
    }

    /**
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function validate(array $output, array $schema): array
    {
        foreach ($schema['required'] ?? [] as $field) {
            if (! array_key_exists($field, $output)) {
                throw new InvalidArgumentException("Reasoning output is missing required field [{$field}].");
            }
        }

        foreach (($schema['properties'] ?? []) as $field => $definition) {
            if (! array_key_exists($field, $output)) {
                continue;
            }

            self::validateValue($output[$field], $definition, $field);
        }

        return $output;
    }

    /** @param array<string, mixed> $definition */
    private static function validateValue(mixed $value, array $definition, string $field): void
    {
        $type = $definition['type'] ?? null;

        $valid = match ($type) {
            'string' => is_string($value),
            'array' => is_array($value),
            'object' => is_array($value),
            default => true,
        };

        if (! $valid) {
            throw new InvalidArgumentException("Reasoning output field [{$field}] has an invalid type.");
        }

        if (isset($definition['enum']) && ! in_array($value, $definition['enum'], true)) {
            throw new InvalidArgumentException("Reasoning output field [{$field}] contains an invalid value.");
        }

        if ($type === 'array' && isset($definition['items'])) {
            foreach ($value as $item) {
                self::validateValue($item, $definition['items'], $field);
            }
        }

        if ($type === 'object') {
            foreach ($definition['required'] ?? [] as $required) {
                if (! array_key_exists($required, $value)) {
                    throw new InvalidArgumentException("Reasoning output object [{$field}] is missing required field [{$required}].");
                }
            }

            foreach (($definition['properties'] ?? []) as $nested => $nestedDefinition) {
                if (array_key_exists($nested, $value)) {
                    self::validateValue($value[$nested], $nestedDefinition, "{$field}.{$nested}");
                }
            }
        }
    }
}