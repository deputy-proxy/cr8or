<?php

namespace App\Services;

use App\Capabilities\CapabilityRegistry;
use App\Enums\CapabilityExecutionMode;
use App\Exceptions\WorkflowDefinitionException;
use App\Experts\Expert;
use App\Models\ExpertDescriptor;
use App\Models\Workflow;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;

final class WorkflowDefinitionValidator
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly ExpertCapabilityResolver $expertCapabilities,
    ) {}

    public function validateWorkflow(Workflow $workflow): void
    {
        $stages = $workflow->stages()->orderBy('sequence')->orderBy('id')->get();

        /** @var list<WorkflowStage> $stageList */
        $stageList = $stages->values()->all();
        $this->validateStages($stageList, $workflow->getKey());
    }

    public function validateVersion(WorkflowVersion $version): void
    {
        $workflow = $version->workflow()->first();

        if ($workflow === null) {
            throw new WorkflowDefinitionException(
                'WorkflowVersion references a missing Workflow.',
                [['code' => 'workflow.missing', 'message' => 'The WorkflowVersion must belong to an existing Workflow.']],
            );
        }

        $definitions = $version->getAttribute('stage_definitions');
        $stages = [];

        foreach (is_array($definitions) ? $definitions : [] as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $stage = new WorkflowStage;
            $stage->setRawAttributes([
                'workflow_id' => $workflow->getKey(),
                'key' => $definition['key'] ?? null,
                'name' => $definition['name'] ?? null,
                'instruction' => $definition['instruction'] ?? null,
                'sequence' => $definition['sequence'] ?? 0,
                'dependencies' => json_encode($definition['dependencies'] ?? [], JSON_THROW_ON_ERROR),
                'expert_slugs' => json_encode($definition['expert_slugs'] ?? [], JSON_THROW_ON_ERROR),
                'capability_slugs' => json_encode($definition['capability_slugs'] ?? [], JSON_THROW_ON_ERROR),
                'capability_input_contract' => json_encode($definition['capability_input_contract'] ?? [], JSON_THROW_ON_ERROR),
                'capability_output_contract' => json_encode($definition['capability_output_contract'] ?? [], JSON_THROW_ON_ERROR),
                'input_contract' => json_encode($definition['input_contract'] ?? [], JSON_THROW_ON_ERROR),
                'output_contract' => json_encode($definition['output_contract'] ?? [], JSON_THROW_ON_ERROR),
                'repeatable' => (bool) ($definition['repeatable'] ?? false),
                'completion_criteria' => json_encode($definition['completion_criteria'] ?? [], JSON_THROW_ON_ERROR),
            ]);
            $stages[] = $stage;
        }

        $this->validateStages($stages, $workflow->getKey());
    }

    /**
     * @param  list<WorkflowStage>  $stages
     */
    private function validateStages(array $stages, int|string $workflowId): void
    {
        $errors = [];
        $keys = [];
        $sequences = [];

        foreach ($stages as $stage) {
            $key = $stage->getAttribute('key');
            $sequence = (int) $stage->getAttribute('sequence');

            if (! is_string($key) || $key === '') {
                $errors[] = ['code' => 'stage.key.invalid', 'message' => 'Every Workflow stage must have a non-empty key.'];

                continue;
            }

            if (isset($keys[$key])) {
                $errors[] = $this->stageError($stage, 'stage.key.duplicate', "Workflow stage [{$key}] is declared more than once.");
            }
            $keys[$key] = true;

            if ($sequence < 1) {
                $errors[] = $this->stageError($stage, 'stage.sequence.invalid', "Workflow stage [{$key}] must have a positive sequence.");
            }

            if (isset($sequences[$sequence])) {
                $errors[] = $this->stageError($stage, 'stage.sequence.duplicate', "Workflow stage sequence [{$sequence}] is declared more than once.");
            }
            $sequences[$sequence] = $key;
        }

        if ($stages === []) {
            $errors[] = ['code' => 'workflow.stages.missing', 'message' => "Workflow [{$workflowId}] must contain at least one stage."];
        }

        foreach ($stages as $stage) {
            $key = (string) $stage->getAttribute('key');
            $dependencies = $this->list($stage->getAttribute('dependencies'));

            foreach ($dependencies as $dependency) {
                if (! isset($keys[$dependency])) {
                    $errors[] = $this->stageError($stage, 'dependency.missing', "Workflow stage [{$key}] references missing dependency [{$dependency}].");

                    continue;
                }

                if ($dependency === $key) {
                    $errors[] = $this->stageError($stage, 'dependency.self', "Workflow stage [{$key}] cannot depend on itself.");
                }
            }

            $expertSlugs = $this->list($stage->getAttribute('expert_slugs'));
            $capabilitySlugs = $this->list($stage->getAttribute('capability_slugs'));

            if ($expertSlugs === []) {
                $errors[] = $this->stageError($stage, 'expert.missing', "Workflow stage [{$key}] must declare at least one Expert.");
            }

            if ($capabilitySlugs === []) {
                $errors[] = $this->stageError($stage, 'capability.missing', "Workflow stage [{$key}] must declare at least one Capability.");
            }

            foreach ($expertSlugs as $expertSlug) {
                $descriptor = ExpertDescriptor::query()->where('slug', $expertSlug)->first();

                if ($descriptor === null) {
                    $errors[] = $this->stageError($stage, 'expert.unknown', "Workflow stage [{$key}] references unknown Expert [{$expertSlug}].", expert: $expertSlug);

                    continue;
                }

                if (! $descriptor->enabled) {
                    $errors[] = $this->stageError($stage, 'expert.disabled', "Workflow stage [{$key}] references disabled Expert [{$expertSlug}].", expert: $expertSlug);

                    continue;
                }

                $runtime = (string) $descriptor->getAttribute('runtime_class');

                if ($runtime === '' || ! class_exists($runtime) || ! is_subclass_of($runtime, Expert::class)) {
                    $errors[] = $this->stageError($stage, 'expert.runtime.invalid', "Expert [{$expertSlug}] does not resolve to a valid Expert runtime.", expert: $expertSlug);

                    continue;
                }

                $expert = app($runtime);
                $expertCapabilities = $expert->capabilities();

                foreach ($capabilitySlugs as $capabilitySlug) {
                    try {
                        $definition = $this->capabilities->resolve($capabilitySlug);
                    } catch (\InvalidArgumentException $exception) {
                        $errors[] = $this->stageError(
                            $stage,
                            'capability.unknown',
                            "Workflow stage [{$key}] references unknown Capability [{$capabilitySlug}].",
                            expert: $expertSlug,
                            capability: $capabilitySlug,
                        );

                        continue;
                    }

                    if (! $definition->supportsExecutionMode(CapabilityExecutionMode::WORKFLOW)) {
                        $errors[] = $this->stageError(
                            $stage,
                            'capability.execution_mode.unsupported',
                            "Capability [{$capabilitySlug}] does not support deterministic Workflow execution.",
                            expert: $expertSlug,
                            capability: $capabilitySlug,
                        );
                    }

                    if (! in_array($capabilitySlug, $expertCapabilities, true)) {
                        $errors[] = $this->stageError(
                            $stage,
                            'expert.capability.mismatch',
                            "Expert [{$expertSlug}] does not expose Capability [{$capabilitySlug}].",
                            expert: $expertSlug,
                            capability: $capabilitySlug,
                        );
                    }

                }
            }

            if (count($expertSlugs) === 1 && count($capabilitySlugs) === 1) {
                try {
                    $definition = $this->expertCapabilities->resolve($expertSlugs[0], $capabilitySlugs[0]);
                    $storedInputContract = $this->array($stage->getAttribute('capability_input_contract'));
                    $storedOutputContract = $this->array($stage->getAttribute('capability_output_contract'));

                    if ($storedInputContract !== [] && $storedInputContract !== $definition->inputContract) {
                        $errors[] = $this->stageError(
                            $stage,
                            'capability.input_contract.mismatch',
                            "Workflow stage [{$key}] contains a Capability input contract that does not match the canonical definition.",
                            expert: $expertSlugs[0],
                            capability: $capabilitySlugs[0],
                        );
                    }

                    if ($storedOutputContract !== [] && $storedOutputContract !== $definition->outputContract) {
                        $errors[] = $this->stageError(
                            $stage,
                            'capability.output_contract.mismatch',
                            "Workflow stage [{$key}] contains a Capability output contract that does not match the canonical definition.",
                            expert: $expertSlugs[0],
                            capability: $capabilitySlugs[0],
                        );
                    }
                } catch (\Throwable $exception) {
                    $errors[] = $this->stageError(
                        $stage,
                        'capability.resolution.failed',
                        $exception->getMessage(),
                        expert: $expertSlugs[0],
                        capability: $capabilitySlugs[0],
                    );
                }
            }

            $errors = [...$errors, ...$this->validateMappings($stage, $keys)];
            $errors = [...$errors, ...$this->validateRequiredInputs($stage, $stages)];
        }

        $errors = [...$errors, ...$this->validateDependencyCycles($stages)];

        if ($errors !== []) {
            throw new WorkflowDefinitionException(
                'The persisted Workflow definition is invalid.',
                $errors,
            );
        }
    }

    /**
     * @param  array<string, bool>  $stageKeys
     * @return list<array{stage?: string, code: string, message: string, expert?: string, capability?: string}>
     */
    private function validateMappings(WorkflowStage $stage, array $stageKeys): array
    {
        $contract = $this->array($stage->getAttribute('input_contract'));
        $mappings = $this->array($contract['mappings'] ?? []);
        $errors = [];

        foreach ($mappings as $target => $source) {
            if ($target === '' || ! is_string($source) || $source === '') {
                $errors[] = $this->stageError($stage, 'mapping.invalid', "Workflow stage [{$stage->key}] contains an invalid input mapping.");

                continue;
            }

            $segments = explode('.', $source);
            $root = $segments[0];

            if ($root === 'stages') {
                if (count($segments) === 1) {
                    continue;
                }

                $sourceStage = $segments[1];
                if (! isset($stageKeys[$sourceStage])) {
                    $errors[] = $this->stageError($stage, 'mapping.stage.missing', "Workflow stage [{$stage->key}] maps from missing stage [{$sourceStage}].");

                    continue;
                }

                $dependencies = $this->list($stage->getAttribute('dependencies'));
                if (! in_array($sourceStage, $dependencies, true)) {
                    $errors[] = $this->stageError($stage, 'mapping.dependency.missing', "Workflow stage [{$stage->key}] maps from stage [{$sourceStage}] without declaring it as a dependency.");
                }

                continue;
            }

            if (in_array($root, $this->runtimeProvidedInputs(), true)) {
                continue;
            }

            if (! isset($stageKeys[$root])) {
                continue;
            }

            $dependencies = $this->list($stage->getAttribute('dependencies'));
            if (! in_array($root, $dependencies, true)) {
                $errors[] = $this->stageError($stage, 'mapping.dependency.missing', "Workflow stage [{$stage->key}] maps from stage [{$root}] without declaring it as a dependency.");
            }
        }

        return $errors;
    }

    /**
     * Required stage inputs must either be supplied by the workflow caller/runtime,
     * have a default, or be explicitly mapped. If an upstream stage exposes the
     * same field, an explicit mapping is mandatory so the data-flow graph is
     * deterministic and inspectable.
     *
     * @param  list<WorkflowStage>  $stages
     * @return list<array{stage?: string, code: string, message: string, expert?: string, capability?: string}>
     */
    private function validateRequiredInputs(WorkflowStage $stage, array $stages): array
    {
        $contract = $this->array($stage->getAttribute('input_contract'));
        $required = $this->list($contract['required'] ?? []);
        $defaults = $this->array($contract['defaults'] ?? []);
        $mappings = $this->array($contract['mappings'] ?? []);
        $errors = [];

        foreach ($required as $input) {
            if (array_key_exists($input, $defaults) || array_key_exists($input, $mappings) || in_array($input, $this->runtimeProvidedInputs(), true)) {
                continue;
            }

            foreach ($stages as $producer) {
                if ($producer->key === $stage->key || (int) $producer->sequence >= (int) $stage->sequence) {
                    continue;
                }

                if ($this->outputContractHasPath($producer->getAttribute('output_contract'), $input)) {
                    $errors[] = $this->stageError(
                        $stage,
                        'mapping.required.missing',
                        "Workflow stage [{$stage->key}] requires [{$input}] from upstream stage [{$producer->key}] but declares no input mapping for it.",
                    );
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Verify that a mapped stage output exists in the producer's declared output
     * contract. Empty contracts are treated as opaque because some capabilities
     * intentionally return dynamic payloads.
     */
    private function outputContractHasPath(mixed $contractValue, string $path): bool
    {
        $contract = $this->array($contractValue);
        if ($contract === []) {
            return true;
        }

        $segments = explode('.', $path);
        $node = $contract;

        foreach ($segments as $segment) {
            if (isset($node['properties']) && is_array($node['properties'])) {
                if (! array_key_exists($segment, $node['properties'])) {
                    return false;
                }
                $node = is_array($node['properties'][$segment]) ? $node['properties'][$segment] : [];

                continue;
            }

            if (array_key_exists($segment, $node)) {
                $node = is_array($node[$segment]) ? $node[$segment] : [];

                continue;
            }

            if (isset($node['required']) && is_array($node['required']) && in_array($segment, $node['required'], true)) {
                $node = [];

                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @param  list<WorkflowStage>  $stages
     * @return list<array{stage?: string, code: string, message: string, expert?: string, capability?: string}>
     */
    private function validateDependencyCycles(array $stages): array
    {
        $graph = [];
        foreach ($stages as $stage) {
            $graph[(string) $stage->key] = $this->list($stage->getAttribute('dependencies'));
        }

        $visited = [];
        $active = [];
        $errors = [];

        $visit = function (string $key) use (&$visit, &$visited, &$active, &$graph, &$errors): void {
            if (($active[$key] ?? false) === true) {
                $errors[] = ['code' => 'dependency.cycle', 'message' => "Workflow dependency cycle detected at stage [{$key}]."];

                return;
            }

            if (($visited[$key] ?? false) === true) {
                return;
            }

            $active[$key] = true;
            foreach ($graph[$key] ?? [] as $dependency) {
                if (isset($graph[$dependency])) {
                    $visit($dependency);
                }
            }
            unset($active[$key]);
            $visited[$key] = true;
        };

        foreach (array_keys($graph) as $key) {
            $visit($key);
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function runtimeProvidedInputs(): array
    {
        return [
            'enterprise',
            'enterprise_id',
            'correlation_id',
            'workflow_execution_id',
            'workflow_version_id',
            'workflow_stage_key',
            'assignment',
            'agent_assignment_id',
            'execution',
            'agent_execution_id',
            'approval',
            'approval_request_id',
            'delegation',
        ];
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array{stage?: string, code: string, message: string, expert?: string, capability?: string}
     */
    private function stageError(WorkflowStage $stage, string $code, string $message, ?string $expert = null, ?string $capability = null): array
    {
        return array_filter([
            'stage' => $stage->getAttribute('key'),
            'code' => $code,
            'message' => $message,
            'expert' => $expert,
            'capability' => $capability,
        ], static fn (mixed $value): bool => $value !== null);
    }
}