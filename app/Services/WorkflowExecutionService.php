<?php

namespace App\Services;

use App\Data\CapabilityInvocationRequest;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class WorkflowExecutionService
{
    public function __construct(private readonly CapabilityInvocationService $capabilities) {}

    /** @param array<string, mixed> $input */
    public function start(User $actor, Workflow|WorkflowVersion $definition, array $input, string $idempotencyKey, ?string $correlationId = null, bool $returnFailed = false): WorkflowExecution
    {
        $version = $definition instanceof WorkflowVersion ? $definition : $definition->publishedVersion;

        if (! $version instanceof WorkflowVersion || $version->status !== WorkflowVersion::STATUS_PUBLISHED) {
            throw new AuthorizationException('Deterministic Workflow execution requires a published WorkflowVersion.');
        }

        $workflow = $version->workflow;
        Gate::forUser($actor)->authorize('view', $workflow);

        $existing = WorkflowExecution::query()
            ->where('workflow_version_id', $version->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->validateExecutionInput($version, $input);

        $execution = DB::transaction(function () use ($actor, $workflow, $version, $input, $idempotencyKey, $correlationId): WorkflowExecution {
            return WorkflowExecution::query()->create([
                'workflow_id' => $workflow->getKey(),
                'workflow_version_id' => $version->getKey(),
                'workflow_version' => $version->version,
                'enterprise_id' => $workflow->enterprise_id,
                'actor_id' => $actor->getKey(),
                'status' => WorkflowExecution::STATUS_PENDING,
                'correlation_id' => $correlationId ?? str()->uuid()->toString(),
                'idempotency_key' => $idempotencyKey,
                'continuation_token' => (string) str()->uuid(),
                'input' => $input,
                'outputs' => [],
                'context' => $this->initialExecutionContext($input, $version),
            ]);
        });

        return $this->continue($actor, $execution, null, $returnFailed);
    }

    /** @return array<string, mixed> */
    public function inspect(User $actor, WorkflowExecution $execution): array
    {
        $execution->loadMissing(['workflow.enterprise', 'workflowVersion']);
        Gate::forUser($actor)->authorize('view', $execution->workflow);

        $outputsValue = $execution->getAttribute('outputs');
        $outputs = is_array($outputsValue) ? $outputsValue : [];
        $next = $this->nextStage($execution);

        return [
            'id' => $execution->getKey(),
            'workflow_id' => $execution->workflow_id,
            'workflow_version_id' => $execution->workflow_version_id,
            'workflow_version' => $execution->workflow_version,
            'status' => $execution->status,
            'current_stage_key' => $execution->current_stage_key,
            'completed_stage_keys' => array_keys($outputs),
            'waiting' => in_array($execution->status, [
                WorkflowExecution::STATUS_WAITING_FOR_INPUT,
                WorkflowExecution::STATUS_WAITING_FOR_APPROVAL,
            ], true),
            'state_reason' => $execution->state_reason,
            'failure_reason' => $execution->failure_reason,
            'outputs' => $outputs,
            'next_stage_key' => $next?->key,
            'correlation_id' => $execution->correlation_id,
            'idempotency_key' => $execution->idempotency_key,
            'continuation_token' => $execution->continuation_token,
        ];
    }

    public function continue(User $actor, WorkflowExecution $execution, ?string $continuationToken = null, bool $returnFailed = false): WorkflowExecution
    {
        try {
            return DB::transaction(function () use ($actor, $execution, $continuationToken, $returnFailed): WorkflowExecution {
                /** @var WorkflowExecution $locked */
                $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

                if ($continuationToken !== null && ! hash_equals((string) $locked->continuation_token, $continuationToken)) {
                    throw new AuthorizationException('Workflow continuation token is stale or invalid.');
                }

                return $this->continueLocked($actor, $locked, $returnFailed);
            });
        } catch (Throwable $exception) {
            /** @var WorkflowExecution|null $failed */
            $failed = WorkflowExecution::query()->find($execution->getKey());

            if ($failed !== null && $failed->status !== WorkflowExecution::STATUS_COMPLETED) {
                $failed->continuation_token = (string) str()->uuid()->toString();
                $failed->fail($exception->getMessage())->save();
            }

            if ($returnFailed && $failed !== null) {
                return $failed->refresh();
            }

            throw $exception;
        }
    }

    private function continueLocked(User $actor, WorkflowExecution $execution, bool $returnFailed = false): WorkflowExecution
    {
        $execution->loadMissing(['workflow.enterprise', 'workflowVersion']);

        if (! $execution->workflowVersion instanceof WorkflowVersion || $execution->workflowVersion->status === WorkflowVersion::STATUS_DRAFT) {
            throw new AuthorizationException('Workflow execution must reference a published WorkflowVersion.');
        }

        Gate::forUser($actor)->authorize('view', $execution->workflow);

        if ($execution->status === WorkflowExecution::STATUS_COMPLETED) {
            return $execution;
        }

        if ($execution->status === WorkflowExecution::STATUS_FAILED) {
            $execution->retry()->save();
        } else {
            $execution->start()->save();
        }

        try {
            while ($stage = $this->nextStage($execution)) {
                $execution->current_stage_key = $stage->key;
                $execution->state_reason = null;
                $execution->save();

                $result = $this->executeStage($actor, $execution, $stage);

                if ($result['status'] === 'waiting') {
                    $contextValue = $execution->getAttribute('context');
                    /** @var array<string, mixed> $context */
                    $context = is_array($contextValue) ? $contextValue : [];
                    $approval = $result['approval'] ?? null;
                    $context['pending_approval_request_id'] = $approval instanceof ApprovalRequest ? $approval->getKey() : null;
                    $execution->setAttribute('context', $context);
                    $execution->continuation_token = (string) str()->uuid();
                    $execution->waitForApproval($result['reason'] ?? 'Workflow stage is waiting for approval.')->save();

                    return $execution->refresh();
                }

                $stageOutput = is_array($result['result'] ?? null) ? $result['result'] : ['value' => $result['result'] ?? null];
                $stageOutput['termination'] = 'completed';

                if (! $stage->completionSatisfied($stageOutput, [$result])) {
                    throw new AuthorizationException("Workflow stage [{$stage->key}] did not satisfy its completion criteria.");
                }

                $outputsValue = $execution->getAttribute('outputs');
                /** @var array<string, mixed> $outputs */
                $outputs = is_array($outputsValue) ? $outputsValue : [];
                $outputs[$stage->key] = $stageOutput;
                $execution->setAttribute('outputs', $outputs);
                $execution->continuation_token = (string) str()->uuid();

                $contextValue = $execution->getAttribute('context');
                /** @var array<string, mixed> $context */
                $context = is_array($contextValue) ? $contextValue : [];
                $context['stages'][$stage->key] = $stageOutput;
                unset($context['pending_approval_request_id']);
                $execution->setAttribute('context', $context);
                $execution->save();
            }

            $execution->complete()->save();

            if ($execution->workflow->status === Workflow::STATUS_PENDING) {
                $execution->workflow->transitionTo(Workflow::STATUS_RUNNING)->save();
            }
            if ($execution->workflow->status === Workflow::STATUS_RUNNING) {
                $execution->workflow->transitionTo(Workflow::STATUS_SUCCEEDED)->save();
            }

            return $execution->refresh();
        } catch (Throwable $exception) {
            $execution->continuation_token = (string) str()->uuid();
            $execution->fail($exception->getMessage())->save();

            if ($execution->workflow->status === Workflow::STATUS_RUNNING) {
                $execution->workflow->transitionTo(Workflow::STATUS_FAILED)->save();
            }

            if ($returnFailed) {
                return $execution->refresh();
            }

            throw $exception;
        }
    }

    /** @return array{status: string, result?: mixed, approval?: ApprovalRequest, reason?: string} */
    private function executeStage(User $actor, WorkflowExecution $execution, WorkflowStage $stage): array
    {
        $expertSlugsValue = $stage->getAttribute('expert_slugs');
        $capabilitySlugsValue = $stage->getAttribute('capability_slugs');
        $expertSlugs = is_array($expertSlugsValue) ? $expertSlugsValue : [];
        $capabilitySlugs = is_array($capabilitySlugsValue) ? $capabilitySlugsValue : [];
        $expertSlug = $expertSlugs[0] ?? null;
        $capability = $capabilitySlugs[0] ?? null;

        if ($expertSlug === null || $capability === null) {
            throw new AuthorizationException("Workflow stage [{$stage->key}] must declare an Expert and Capability.");
        }

        $contextValue = $execution->getAttribute('context');
        /** @var array<string, mixed> $context */
        $context = is_array($contextValue) ? $contextValue : [];
        $approval = isset($context['pending_approval_request_id'])
            ? ApprovalRequest::query()->find((int) $context['pending_approval_request_id'])
            : null;

        $inputContractValue = $stage->getAttribute('input_contract');
        /** @var array<string, mixed> $inputContract */
        $inputContract = is_array($inputContractValue) ? $inputContractValue : [];
        $requiredValue = $inputContract['required'] ?? [];
        $required = is_array($requiredValue) ? $requiredValue : [];

        $inputValue = $execution->getAttribute('input');
        /** @var array<string, mixed> $executionInput */
        $executionInput = is_array($inputValue) ? $inputValue : [];
        $input = $this->resolveStageInput($stage, $executionInput);
        $mappedInput = $this->resolveMappedInput($stage, $context);
        $defaultsValue = $inputContract['defaults'] ?? [];
        /** @var array<string, mixed> $defaults */
        $defaults = is_array($defaultsValue) ? $defaultsValue : [];
        $available = array_merge($input, $mappedInput, $defaults, $context);
        $inputPayload = array_merge($defaults, $input, $mappedInput);

        foreach ($required as $key) {
            if (is_string($key) && ! array_key_exists($key, $available)) {
                throw ValidationException::withMessages([
                    "workflow.{$stage->key}.{$key}" => "Workflow stage [{$stage->key}] is missing required input [{$key}].",
                ]);
            }
        }

        $request = new CapabilityInvocationRequest(
            capability: $capability,
            actor: $actor,
            enterprise: $execution->enterprise,
            targetContext: [
                'workflow_execution_id' => $execution->getKey(),
                'workflow_version_id' => $execution->workflow_version_id,
                'workflow_stage_key' => $stage->key,
                'workflow_stage_instruction' => $stage->instruction,
                ...$context,
            ],
            inputPayload: $inputPayload,
            correlationId: $execution->correlation_id,
            idempotencyKey: $execution->idempotency_key.':'.$stage->key,
            expertSlug: $expertSlug,
            approval: $approval,
            workflowExecution: $execution,
            workflowStage: $stage,
        );

        return $this->capabilities->invoke($request);
    }

    /**
     * Build the durable execution context from workflow-level input.
     *
     * Structured input keeps workflow-level values under `workflow` so they cannot
     * overwrite stage input when the Capability request target context is assembled.
     * Legacy flat input preserves the historical context shape for existing workflows.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function initialExecutionContext(array $input, WorkflowVersion $version): array
    {
        if (! array_key_exists('stages', $input)) {
            return [
                ...$input,
                'workflow_version_id' => $version->getKey(),
                'workflow_version' => $version->version,
            ];
        }

        $workflowInput = is_array($input['workflow'] ?? null) ? $input['workflow'] : [];

        return [
            'workflow' => $workflowInput,
            'workflow_version_id' => $version->getKey(),
            'workflow_version' => $version->version,
        ];
    }

    /**
     * Resolve caller-supplied input for the current stage.
     *
     * Structured execution input uses `stages.<stage_key>`. The legacy flat input
     * shape remains supported for existing workflows that have not yet migrated.
     *
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
     * Validate the top-level structure of a structured Workflow execution input.
     *
     * @param  array<string, mixed>  $input
     */
    private function validateExecutionInput(WorkflowVersion $version, array $input): void
    {
        if (! array_key_exists('stages', $input)) {
            return;
        }

        if (! is_array($input['stages'])) {
            throw ValidationException::withMessages([
                'workflow.stages' => 'Workflow execution input [stages] must be an object keyed by Workflow stage key.',
            ]);
        }

        $definitions = $version->getAttribute('stage_definitions');
        $stageDefinitions = is_array($definitions) ? $definitions : [];
        $knownStages = [];
        $allowedInputs = [];

        foreach ($stageDefinitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                continue;
            }

            $key = $definition['key'];
            $knownStages[$key] = true;
            $contract = $definition['capability_input_contract'] ?? [];
            $allowedInputs[$key] = is_array($contract) ? array_keys($contract) : [];
        }

        $errors = [];

        foreach ($input['stages'] as $stageKey => $stageInput) {
            if (! is_string($stageKey) || ! isset($knownStages[$stageKey])) {
                $errors["workflow.stages.{$stageKey}"] = "Workflow execution input references unknown stage [{$stageKey}].";

                continue;
            }

            if (! is_array($stageInput)) {
                $errors["workflow.{$stageKey}"] = "Workflow stage input [{$stageKey}] must be an object.";

                continue;
            }

            $allowed = $allowedInputs[$stageKey] ?? [];

            if ($allowed === []) {
                continue;
            }

            foreach (array_keys($stageInput) as $inputKey) {
                if (is_string($inputKey) && ! in_array($inputKey, $allowed, true)) {
                    $errors["workflow.{$stageKey}.{$inputKey}"] = "Workflow stage [{$stageKey}] does not declare input [{$inputKey}].";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveMappedInput(WorkflowStage $stage, array $context): array
    {
        $contractValue = $stage->getAttribute('input_contract');
        $contract = is_array($contractValue) ? $contractValue : [];
        $mappings = is_array($contract['mappings'] ?? null) ? $contract['mappings'] : [];
        $resolved = [];

        foreach ($mappings as $target => $source) {
            if (! is_string($target) || ! is_string($source)) {
                continue;
            }

            $asArray = str_ends_with($source, '[]');
            $path = $asArray ? substr($source, 0, -2) : $source;

            $value = $context;
            foreach (explode('.', $path) as $segment) {
                if (! is_array($value) || ! array_key_exists($segment, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$segment];
            }

            if ($value !== null) {
                $resolved[$target] = $asArray ? [$value] : $value;
            }
        }

        return $resolved;
    }

    private function nextStage(WorkflowExecution $execution): ?WorkflowStage
    {
        $definitionsRaw = $execution->workflowVersion?->getRawOriginal('stage_definitions');
        if (is_array($definitionsRaw)) {
            $definitions = $definitionsRaw;
        } elseif (is_string($definitionsRaw) && $definitionsRaw !== '') {
            $decoded = json_decode($definitionsRaw, true);
            $definitions = is_array($decoded) ? $decoded : [];
        } else {
            $definitions = [];
        }
        $outputsValue = $execution->getAttribute('outputs');
        /** @var array<string, mixed> $outputs */
        $outputs = is_array($outputsValue) ? $outputsValue : [];
        $completed = array_keys($outputs);

        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                continue;
            }

            $key = $definition['key'];

            if (in_array($key, $completed, true) && ! ($definition['repeatable'] ?? false)) {
                continue;
            }

            $dependencies = is_array($definition['dependencies'] ?? null) ? $definition['dependencies'] : [];
            $ready = true;
            foreach ($dependencies as $dependency) {
                if (! is_string($dependency) || ! in_array($dependency, $completed, true)) {
                    $ready = false;
                    break;
                }
            }

            if (! $ready) {
                continue;
            }

            $stage = new WorkflowStage;
            $stage->setRawAttributes([
                'workflow_id' => $execution->workflow_id,
                'key' => $key,
                'name' => $definition['name'] ?? $key,
                'instruction' => $definition['instruction'] ?? null,
                'sequence' => $definition['sequence'] ?? 0,
                'dependencies' => json_encode($dependencies, JSON_THROW_ON_ERROR),
                'expert_slugs' => json_encode($definition['expert_slugs'] ?? [], JSON_THROW_ON_ERROR),
                'capability_slugs' => json_encode($definition['capability_slugs'] ?? [], JSON_THROW_ON_ERROR),
                'input_contract' => json_encode($definition['input_contract'] ?? [], JSON_THROW_ON_ERROR),
                'output_contract' => json_encode($definition['output_contract'] ?? [], JSON_THROW_ON_ERROR),
                'repeatable' => (bool) ($definition['repeatable'] ?? false),
                'completion_criteria' => json_encode($definition['completion_criteria'] ?? [], JSON_THROW_ON_ERROR),
            ]);

            return $stage;
        }

        return null;
    }
}