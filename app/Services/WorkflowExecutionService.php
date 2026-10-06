<?php

namespace App\Services;

use App\Data\CapabilityInvocationRequest;
use App\Data\ResolvedWorkflowStageInput;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use App\Models\WorkflowVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

final class WorkflowExecutionService
{
    public function __construct(
        private readonly CapabilityInvocationService $capabilities,
        private readonly WorkflowStageInputResolver $inputResolver,
    ) {}

    /** @param array<string, mixed> $input */
    public function start(User $actor, Workflow|WorkflowVersion $definition, array $input, string $idempotencyKey, ?string $correlationId = null, bool $returnFailed = false, ?Enterprise $enterprise = null): WorkflowExecution
    {
        $version = $definition instanceof WorkflowVersion ? $definition : $definition->publishedVersion;

        if (! $version instanceof WorkflowVersion || $version->status !== WorkflowVersion::STATUS_PUBLISHED) {
            throw new AuthorizationException('Deterministic Workflow execution requires a published WorkflowVersion.');
        }

        $workflow = $version->workflow;
        $enterprise ??= $workflow->enterprise;

        if (! $enterprise instanceof Enterprise) {
            throw new AuthorizationException('Generic Workflow execution requires an enterprise context.');
        }

        Gate::forUser($actor)->authorize('viewForEnterprise', [$workflow, $enterprise]);

        if (
            $version->enterprise_id !== null
            && (int) $version->enterprise_id !== (int) $enterprise->getKey()
        ) {
            throw new AuthorizationException('WorkflowVersion is not available for the execution Enterprise.');
        }

        $existing = WorkflowExecution::query()
            ->where('workflow_version_id', $version->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->validateExecutionInput($version, $input);

        $execution = DB::transaction(function () use ($actor, $workflow, $version, $enterprise, $input, $idempotencyKey, $correlationId): WorkflowExecution {
            return WorkflowExecution::query()->create([
                'workflow_id' => $workflow->getKey(),
                'workflow_version_id' => $version->getKey(),
                'workflow_version' => $version->version,
                'enterprise_id' => $enterprise->getKey(),
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
        $claimed = DB::transaction(function () use ($actor, $execution, $continuationToken): WorkflowExecution {
            /** @var WorkflowExecution $locked */
            $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

            if ($continuationToken !== null && ! hash_equals((string) $locked->continuation_token, $continuationToken)) {
                throw new AuthorizationException('Workflow continuation token is stale or invalid.');
            }

            $locked->loadMissing(['workflow.enterprise', 'workflowVersion']);

            if (! $locked->workflowVersion instanceof WorkflowVersion || $locked->workflowVersion->status === WorkflowVersion::STATUS_DRAFT) {
                throw new AuthorizationException('Workflow execution must reference a published WorkflowVersion.');
            }

            Gate::forUser($actor)->authorize('view', $locked->workflow);

            if ($locked->status === WorkflowExecution::STATUS_COMPLETED) {
                return $locked;
            }

            if ($locked->status === WorkflowExecution::STATUS_RUNNING) {
                throw new LogicException('Workflow execution is already running.');
            }

            if ($locked->status === WorkflowExecution::STATUS_FAILED) {
                $locked->retry();
            } else {
                $locked->start();
            }

            // Rotate the token before releasing the lock so another caller cannot
            // claim the same continuation while this execution is running.
            $locked->continuation_token = (string) str()->uuid();
            $locked->save();

            return $locked->refresh();
        });

        if ($claimed->status === WorkflowExecution::STATUS_COMPLETED) {
            return $claimed;
        }

        return $this->runStages($actor, $claimed, $returnFailed);
    }

    private function runStages(User $actor, WorkflowExecution $execution, bool $returnFailed = false): WorkflowExecution
    {
        try {
            while (true) {
                $stage = DB::transaction(function () use ($execution): ?WorkflowStage {
                    /** @var WorkflowExecution $locked */
                    $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

                    if ($locked->status !== WorkflowExecution::STATUS_RUNNING) {
                        return null;
                    }

                    $stage = $this->nextStage($locked);

                    if ($stage === null) {
                        $locked->complete()->save();

                        if ($locked->workflow->status === Workflow::STATUS_PENDING) {
                            $locked->workflow->transitionTo(Workflow::STATUS_RUNNING)->save();
                        }
                        if ($locked->workflow->status === Workflow::STATUS_RUNNING) {
                            $locked->workflow->transitionTo(Workflow::STATUS_SUCCEEDED)->save();
                        }

                        return null;
                    }

                    $locked->current_stage_key = $stage->key;
                    $locked->state_reason = null;
                    $locked->save();

                    return $stage;
                });

                if ($stage === null) {
                    return $execution->refresh();
                }

                // The capability is deliberately invoked outside any transaction and
                // therefore outside the WorkflowExecution row lock.
                $result = $this->executeStage($actor, $execution->refresh(), $stage);

                $state = DB::transaction(function () use ($execution, $stage, $result): array {
                    /** @var WorkflowExecution $locked */
                    $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

                    if ($locked->status !== WorkflowExecution::STATUS_RUNNING || $locked->current_stage_key !== $stage->key) {
                        throw new LogicException("Workflow execution changed while stage [{$stage->key}] was executing.");
                    }

                    if ($result['status'] === 'waiting') {
                        $contextValue = $locked->getAttribute('context');
                        /** @var array<string, mixed> $context */
                        $context = is_array($contextValue) ? $contextValue : [];
                        $approval = $result['approval'] ?? null;
                        $context['pending_approval_request_id'] = $approval instanceof ApprovalRequest ? $approval->getKey() : null;
                        $locked->setAttribute('context', $context);
                        $locked->continuation_token = (string) str()->uuid();
                        $locked->waitForApproval($result['reason'] ?? 'Workflow stage is waiting for approval.')->save();

                        return ['waiting' => true];
                    }

                    if ($result['status'] === 'waiting_for_input') {
                        $locked->continuation_token = (string) str()->uuid();
                        $locked->waitForInput($result['reason'] ?? 'Workflow stage is waiting for input.')->save();

                        return ['waiting' => true];
                    }

                    $stageOutput = is_array($result['result'] ?? null) ? $result['result'] : ['value' => $result['result'] ?? null];
                    $stageOutput['termination'] = 'completed';

                    if (! $stage->completionSatisfied($stageOutput, [$result])) {
                        throw new AuthorizationException("Workflow stage [{$stage->key}] did not satisfy its completion criteria.");
                    }

                    $outputsValue = $locked->getAttribute('outputs');
                    /** @var array<string, mixed> $outputs */
                    $outputs = is_array($outputsValue) ? $outputsValue : [];
                    $outputs[$stage->key] = $stageOutput;
                    $locked->setAttribute('outputs', $outputs);
                    $locked->continuation_token = (string) str()->uuid();

                    $contextValue = $locked->getAttribute('context');
                    /** @var array<string, mixed> $context */
                    $context = is_array($contextValue) ? $contextValue : [];
                    $context['stages'][$stage->key] = $stageOutput;
                    unset($context['pending_approval_request_id']);
                    $locked->setAttribute('context', $context);
                    $locked->save();

                    return ['waiting' => false];
                });

                if ($state['waiting']) {
                    return $execution->refresh();
                }
            }
        } catch (Throwable $exception) {
            DB::transaction(function () use ($execution, $exception): void {
                /** @var WorkflowExecution $locked */
                $locked = WorkflowExecution::query()->lockForUpdate()->whereKey($execution->getKey())->firstOrFail();

                if ($locked->status !== WorkflowExecution::STATUS_COMPLETED) {
                    $locked->continuation_token = (string) str()->uuid();
                    $locked->fail($exception->getMessage())->save();

                    if ($locked->workflow->status === Workflow::STATUS_RUNNING) {
                        $locked->workflow->transitionTo(Workflow::STATUS_FAILED)->save();
                    }
                }
            });

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

        $inputValue = $execution->getAttribute('input');
        /** @var array<string, mixed> $executionInput */
        $executionInput = is_array($inputValue) ? $inputValue : [];
        $version = $execution->workflowVersion()->firstOrFail();
        $resolved = $this->inputResolver->resolve($execution, $version, $stage, $executionInput);

        if ($resolved->requested !== []) {
            $this->persistInputResolution($execution, $stage, $resolved);

            return [
                'status' => 'waiting_for_input',
                'reason' => 'Workflow stage requires additional input: '.implode(', ', $resolved->requested).'.',
            ];
        }

        $inputPayload = $resolved->inputs;
        $this->persistInputResolution($execution, $stage, $resolved);

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

    private function persistInputResolution(WorkflowExecution $execution, WorkflowStage $stage, ResolvedWorkflowStageInput $resolved): void
    {
        $contextValue = $execution->getAttribute('context');
        /** @var array<string, mixed> $context */
        $context = is_array($contextValue) ? $contextValue : [];
        $resolutions = is_array($context['stage_input_resolutions'] ?? null)
            ? $context['stage_input_resolutions']
            : [];
        $resolutions[$stage->key] = $resolved->provenance();
        $context['stage_input_resolutions'] = $resolutions;
        $execution->setAttribute('context', $context);
        $execution->save();
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