<?php

namespace App\Services;

use App\AI\Contracts\ModelProvider;
use App\AI\Data\ModelRequest;
use App\Data\ResolvedWorkflowStageInput;
use App\Exceptions\WorkflowDefinitionException;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Illuminate\Validation\ValidationException;

final class WorkflowStageInputResolver
{
    public function __construct(
        private readonly ModelProvider $provider,
    ) {}

    /**
     * @param  array<string, mixed>  $executionInput
     */
    public function resolve(
        WorkflowExecution $execution,
        WorkflowVersion $version,
        WorkflowStage $stage,
        array $executionInput,
    ): ResolvedWorkflowStageInput {
        $contract = $this->array($stage->getAttribute('input_contract'));
        $required = $this->list($contract['required'] ?? []);
        $defaults = $this->array($contract['defaults'] ?? []);
        $mappings = $this->array($contract['mappings'] ?? []);
        $generated = $this->list($contract['generated'] ?? []);
        $requested = $this->list($contract['requested'] ?? []);

        $supplied = $this->resolveStageInput($stage, $executionInput);
        $context = $this->executionContext($execution);
        $mapped = $this->resolveMappedInput($mappings, $context);
        $trusted = ['enterprise_id' => $execution->enterprise_id];

        /** @var array<string, mixed> $inputs */
        $inputs = [];
        /** @var array<string, string> $sources */
        $sources = [];
        /** @var array<string, string> $mappingPaths */
        $mappingPaths = [];

        foreach ($defaults as $key => $value) {
            $inputs[$key] = $value;
            $sources[$key] = 'default';
        }

        foreach ($supplied as $key => $value) {
            $inputs[$key] = $value;
            $sources[$key] = 'explicit';
        }

        foreach ($mapped as $key => $value) {
            $inputs[$key] = $value;
            $sources[$key] = 'mapped';
            $mappingPaths[$key] = (string) ($mappings[$key] ?? '');
        }

        foreach ($trusted as $key => $value) {
            $inputs[$key] = $value;
            $sources[$key] = 'context';
        }

        $available = array_merge($context, $inputs);
        $missing = $this->missingRequired($required, $available);
        $generatedFields = array_values(array_intersect($generated, $missing));
        /** @var array<string, mixed> $metadata */
        $metadata = [];

        if ($generatedFields !== []) {
            $this->assertGenerationAllowed($version, $stage);

            [$generatedValues, $generationMetadata] = $this->generate(
                execution: $execution,
                version: $version,
                stage: $stage,
                contract: $contract,
                fields: $generatedFields,
                inputs: $inputs,
                context: $context,
            );

            $metadata['generation'] = $generationMetadata;

            foreach ($generatedValues as $key => $value) {
                $inputs[$key] = $value;
                $sources[$key] = 'generated';
            }
        }

        $available = array_merge($context, $inputs);
        $missing = $this->missingRequired($required, $available);

        if ($missing !== []) {
            $requestedMissing = array_values(array_intersect($requested, $missing));

            if ($requestedMissing !== []) {
                return new ResolvedWorkflowStageInput(
                    inputs: $inputs,
                    sources: $sources,
                    mappings: $mappingPaths,
                    generated: $generatedFields,
                    requested: $requestedMissing,
                    unresolved: $missing,
                    metadata: $metadata,
                );
            }

            throw ValidationException::withMessages(
                array_combine(
                    array_map(
                        static fn (string $key): string => "workflow.{$stage->key}.{$key}",
                        $missing,
                    ),
                    array_map(
                        static fn (string $key): string => "Workflow stage [{$stage->key}] is missing required input [{$key}].",
                        $missing,
                    ),
                ),
            );
        }

        $validation = $this->validateTypes($stage, $inputs);

        return new ResolvedWorkflowStageInput(
            inputs: $inputs,
            sources: $sources,
            mappings: $mappingPaths,
            generated: $generatedFields,
            validation: $validation,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $contract
     * @param  list<string>  $fields
     * @param  array<string, mixed>  $inputs
     * @param  array<string, mixed>  $context
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function generate(
        WorkflowExecution $execution,
        WorkflowVersion $version,
        WorkflowStage $stage,
        array $contract,
        array $fields,
        array $inputs,
        array $context,
    ): array {
        /** @var array<string, string> $definitions */
        $definitions = $this->array($stage->getAttribute('capability_input_contract'));
        $schema = $this->schemaFor($definitions, $fields);
        $policyValue = $version->getAttribute('execution_policy');
        /** @var array<string, mixed> $policy */
        $policy = is_array($policyValue) ? $policyValue : [];

        $request = new ModelRequest(
            prompt: 'Generate only the missing Workflow stage input fields required by the persisted stage contract.',
            instructions: (string) $stage->getAttribute('instruction'),
            context: [
                'workflow' => [
                    'id' => $execution->workflow_id,
                    'version_id' => $version->getKey(),
                    'version' => $version->version,
                    'purpose' => $version->purpose,
                    'execution_policy' => $policy,
                ],
                'stage' => [
                    'key' => $stage->key,
                    'name' => $stage->name,
                    'instruction' => $stage->instruction,
                    'required_inputs' => $contract['required'] ?? [],
                    'generated_inputs' => $fields,
                    'capability_input_contract' => $definitions,
                ],
                'resolved_inputs' => $inputs,
                'execution_context' => $context,
                'previous_stage_outputs' => is_array($context['stages'] ?? null) ? $context['stages'] : [],
            ],
            provider: is_string($policy['model_provider'] ?? null) ? $policy['model_provider'] : null,
            model: is_string($policy['model'] ?? null) ? $policy['model'] : null,
            timeout: isset($policy['model_timeout']) ? (int) $policy['model_timeout'] : null,
            structuredOutputSchema: $schema,
            correlationId: $execution->correlation_id,
        );

        $result = $this->provider->generate($request);

        if ($result->structured === null) {
            throw new WorkflowDefinitionException(
                "Workflow stage [{$stage->key}] input generation did not return structured output.",
                [['code' => 'stage.input_generation.invalid_response', 'message' => 'Generated workflow stage input must be a structured object.']],
            );
        }

        /** @var array<string, mixed> $values */
        $values = [];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $result->structured) || $result->structured[$field] === null || $result->structured[$field] === '') {
                continue;
            }

            $values[$field] = $result->structured[$field];
        }

        return [$values, [
            'provider' => $result->provider,
            'model' => $result->model,
            'invocation_id' => $result->invocationId,
        ]];
    }

    /**
     * @param  array<string, string>  $definitions
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function schemaFor(array $definitions, array $fields): array
    {
        /** @var array<string, mixed> $properties */
        $properties = [];

        foreach ($fields as $field) {
            $contract = $definitions[$field] ?? 'string';
            $type = str_contains($contract, 'integer') ? 'integer'
                : (str_contains($contract, 'number') ? 'number'
                : (str_contains($contract, 'boolean') ? 'boolean'
                : (str_contains($contract, 'array') ? 'array'
                : (str_contains($contract, 'object') ? 'object' : 'string'))));

            $properties[$field] = ['type' => $type];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $fields,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, string>
     */
    private function validateTypes(WorkflowStage $stage, array $inputs): array
    {
        /** @var array<string, string> $definitions */
        $definitions = $this->array($stage->getAttribute('capability_input_contract'));
        /** @var array<string, string> $errors */
        $errors = [];

        foreach ($definitions as $field => $contract) {
            if (! array_key_exists($field, $inputs) || $inputs[$field] === null) {
                continue;
            }

            $valid = match (true) {
                str_contains($contract, 'integer') => is_int($inputs[$field]) || (is_string($inputs[$field]) && ctype_digit($inputs[$field])),
                str_contains($contract, 'number') => is_int($inputs[$field]) || is_float($inputs[$field]) || is_numeric($inputs[$field]),
                str_contains($contract, 'boolean') => is_bool($inputs[$field]),
                str_contains($contract, 'array') => is_array($inputs[$field]),
                str_contains($contract, 'object') => is_array($inputs[$field]),
                str_contains($contract, 'string') => is_string($inputs[$field]),
                default => true,
            };

            if (! $valid) {
                $errors[$field] = "Workflow stage [{$stage->key}] input [{$field}] does not match its Capability contract [{$contract}].";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(
                array_combine(
                    array_map(static fn (string $key): string => "workflow.{$stage->key}.{$key}", array_keys($errors)),
                    array_values($errors),
                ),
            );
        }

        return array_fill_keys(array_keys($inputs), 'validated');
    }

    /**
     * @param  list<string>  $required
     * @param  array<string, mixed>  $inputs
     * @return list<string>
     */
    private function missingRequired(array $required, array $inputs): array
    {
        return array_values(array_filter(
            $required,
            static fn (string $key): bool => ! array_key_exists($key, $inputs)
                || $inputs[$key] === null
                || $inputs[$key] === '',
        ));
    }

    /**
     * @param  array<string, mixed>  $executionInput
     * @return array<string, mixed>
     */
    private function resolveStageInput(WorkflowStage $stage, array $executionInput): array
    {
        if (! array_key_exists('stages', $executionInput)) {
            return $executionInput;
        }

        $stages = $executionInput['stages'];

        if (! is_array($stages)) {
            return [];
        }

        $stageInput = $stages[$stage->key] ?? [];

        return is_array($stageInput) ? $stageInput : [];
    }

    /**
     * @param  array<string, mixed>  $mappings
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveMappedInput(array $mappings, array $context): array
    {
        /** @var array<string, mixed> $resolved */
        $resolved = [];

        foreach ($mappings as $target => $source) {
            if (! is_string($source) || $target === '' || $source === '') {
                continue;
            }

            $asArray = str_ends_with($source, '[]');
            $path = $asArray ? substr($source, 0, -2) : $source;
            $value = data_get($context, $path);

            if ($value !== null) {
                $resolved[$target] = $asArray ? [$value] : $value;
            }
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function executionContext(WorkflowExecution $execution): array
    {
        $value = $execution->getAttribute('context');

        return is_array($value) ? $value : [];
    }

    private function assertGenerationAllowed(WorkflowVersion $version, WorkflowStage $stage): void
    {
        $policyValue = $version->getAttribute('execution_policy');
        /** @var array<string, mixed> $policy */
        $policy = is_array($policyValue) ? $policyValue : [];

        if (($policy['requires_model_provider'] ?? false) !== true) {
            throw new WorkflowDefinitionException(
                "Workflow stage [{$stage->key}] requests generated input but the published WorkflowVersion does not permit a ModelProvider.",
                [['code' => 'stage.input_generation.provider_required', 'message' => 'Generated stage inputs require execution_policy.requires_model_provider=true.']],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''))
            : [];
    }
}
