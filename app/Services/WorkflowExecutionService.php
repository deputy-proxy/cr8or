<?php

namespace App\Services;

use App\Data\CapabilityInvocationRequest;
use App\Models\ApprovalRequest;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStage;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class WorkflowExecutionService
{
    public function __construct(private readonly CapabilityInvocationService $capabilities) {}

    /** @param array<string, mixed> $input */
    public function start(User $actor, Workflow $workflow, array $input, string $idempotencyKey, ?string $correlationId = null): WorkflowExecution
    {
        Gate::forUser($actor)->authorize('view', $workflow);

        $existing = WorkflowExecution::query()
            ->where('workflow_id', $workflow->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($actor, $workflow, $input, $idempotencyKey, $correlationId): WorkflowExecution {
            $execution = WorkflowExecution::query()->create([
                'workflow_id' => $workflow->getKey(),
                'workflow_version' => $workflow->version,
                'organization_id' => $workflow->enterprise->organization_id,
                'enterprise_id' => $workflow->enterprise_id,
                'actor_id' => $actor->getKey(),
                'status' => WorkflowExecution::STATUS_PENDING,
                'correlation_id' => $correlationId ?? str()->uuid()->toString(),
                'idempotency_key' => $idempotencyKey,
                'input' => $input,
                'outputs' => [],
                'context' => $input,
            ]);

            return $this->continue($actor, $execution);
        });
    }

    public function continue(User $actor, WorkflowExecution $execution): WorkflowExecution
    {
        $execution->loadMissing(['workflow.enterprise', 'currentStage']);
        Gate::forUser($actor)->authorize('view', $execution->workflow);

        if (in_array($execution->status, [WorkflowExecution::STATUS_COMPLETED, WorkflowExecution::STATUS_FAILED], true)) {
            return $execution;
        }

        $execution->start()->save();

        try {
            while ($stage = $this->nextStage($execution)) {
                $execution->current_stage_id = $stage->getKey();
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
                    $execution->waitForApproval('Workflow stage is waiting for approval.')->save();

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
            $execution->fail($exception->getMessage())->save();

            if ($execution->workflow->status === Workflow::STATUS_RUNNING) {
                $execution->workflow->transitionTo(Workflow::STATUS_FAILED)->save();
            }

            throw $exception;
        }
    }

    /** @return array{status: string, result?: mixed, approval?: ApprovalRequest} */
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
        /** @var array<string, mixed> $input */
        $input = is_array($inputValue) ? $inputValue : [];
        $available = array_merge($input, $context);
        foreach ($required as $key) {
            if (is_string($key) && ! array_key_exists($key, $available)) {
                throw new AuthorizationException("Workflow stage [{$stage->key}] is missing required input [{$key}].");
            }
        }

        $request = new CapabilityInvocationRequest(
            capability: $capability,
            actor: $actor,
            enterprise: $execution->enterprise,
            targetContext: [
                'workflow_execution_id' => $execution->getKey(),
                'workflow_stage_id' => $stage->getKey(),
                ...$context,
            ],
            inputPayload: $input,
            correlationId: $execution->correlation_id,
            idempotencyKey: $execution->idempotency_key.':'.$stage->key,
            expertSlug: $expertSlug,
            approval: $approval,
            workflowExecution: $execution,
            workflowStage: $stage,
        );

        return $this->capabilities->invoke($request);
    }

    private function nextStage(WorkflowExecution $execution): ?WorkflowStage
    {
        $stages = $execution->workflow->stages()->get();
        $outputsValue = $execution->getAttribute('outputs');
        /** @var array<string, mixed> $outputs */
        $outputs = is_array($outputsValue) ? $outputsValue : [];
        $completed = array_keys($outputs);

        return $stages->first(function (WorkflowStage $stage) use ($completed): bool {
            if (in_array($stage->key, $completed, true) && ! $stage->repeatable) {
                return false;
            }

            $stage->assertDependenciesSatisfied($completed);

            return true;
        });
    }
}