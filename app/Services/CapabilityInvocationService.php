<?php

namespace App\Services;

use App\AI\Contracts\FailureProvenance;
use App\Capabilities\CapabilityDefinition;
use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityInvocationRequest;
use App\Data\CapabilityRequest;
use App\Events\CapabilityAuthorized;
use App\Events\CapabilityRequested;
use App\Events\CapabilityResultReceived;
use App\Events\OperationExecuted;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class CapabilityInvocationService
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly AgentCapabilityAuthorizer $authorizer,
        private readonly ApprovalRequestService $approvals,
    ) {}

    /** @return array{status: 'executed'|'waiting', capability: string, result?: mixed, raw_result?: mixed, approval?: ApprovalRequest, provenance: array<string, mixed>} */
    public function invoke(CapabilityInvocationRequest $request): array
    {
        $definition = $this->capabilities->resolve($request->capability);
        $this->dispatchRequested($request, $definition);
        $this->authorize($request);

        $permission = $request->assignment?->permissions()
            ->where('capability', $request->capability)
            ->first();

        if ($permission?->requires_approval === true && $request->approval === null) {
            $approval = $this->approvals->request(
                actor: $request->actor,
                capability: $request->capability,
                assignment: $request->assignment,
                execution: $request->execution,
                targetContext: $request->targetContext,
                correlationId: $request->resolvedCorrelationId(),
            );

            return [
                'status' => 'waiting',
                'capability' => $request->capability,
                'approval' => $approval,
                'provenance' => $this->provenance($request, $definition->operation),
            ];
        }

        if ($permission?->requires_approval === true) {
            if (! $request->approval->isValid()) {
                throw new AuthorizationException(
                    "Approval [{$request->approval->getKey()}] is not valid for capability [{$request->capability}].",
                );
            }

            $this->approvals->consume($request->approval, $request->execution);
        }

        $this->dispatchAuthorized($request, $definition);
        $result = $this->execute($request, $definition);

        if ($request->execution instanceof AgentExecution) {
            app(AgentExecutionEventService::class)->dispatch(
                OperationExecuted::class,
                $request->execution,
                provenance: $this->provenance($request, $definition->operation),
                data: ['result_type' => get_debug_type($result)],
            );
            app(AgentExecutionEventService::class)->dispatch(
                CapabilityResultReceived::class,
                $request->execution,
                provenance: $this->provenance($request, $definition->operation),
                data: ['status' => 'executed', 'result_type' => get_debug_type($result)],
            );
        }

        return [
            'status' => 'executed',
            'capability' => $definition->key,
            'result' => $this->normalizeResult($result),
            'raw_result' => $result,
            'provenance' => $this->provenance($request, $definition->operation),
        ];
    }

    private function authorize(CapabilityInvocationRequest $request): void
    {
        if ($request->isAgentBacked()) {
            $agentRequest = new CapabilityRequest(
                capability: $request->capability,
                assignment: $request->assignment,
                execution: $request->execution,
                actor: $request->actor,
                targetContext: $request->targetContext,
                inputPayload: $request->inputPayload,
                expertSlug: $request->expertSlug,
                approval: $request->approval,
                correlationId: $request->resolvedCorrelationId(),
                idempotencyKey: $request->idempotencyKey,
                delegation: $request->delegation,
            );

            $permission = $request->assignment->permissions()
                ->where('capability', $request->capability)
                ->first();

            if ($permission === null || ! $this->authorizer->allowsRequest(
                $agentRequest,
                $permission->requires_approval && $request->approval === null,
            )) {
                throw new AuthorizationException(
                    "The Agent is not authorized for capability [{$request->capability}] in this target context.",
                );
            }

            return;
        }

        if ($request->enterprise === null) {
            if ($request->capability !== 'enterprise.create') {
                throw new AuthorizationException('A Capability invocation requires an Enterprise context.');
            }

            $organization = Organization::query()->findOrFail((int) ($request->inputPayload['organization_id'] ?? 0));
            Gate::forUser($request->actor)->authorize('view', $organization);

            return;
        }

        Gate::forUser($request->actor)->authorize('view', $request->enterprise);
    }

    private function execute(CapabilityInvocationRequest $request, CapabilityDefinition $definition): mixed
    {
        $input = array_merge(
            $request->inputPayload,
            $request->targetContext,
            [
                'enterprise' => $request->enterprise,
                'enterprise_id' => $request->enterprise?->getKey(),
                'correlation_id' => $request->resolvedCorrelationId(),
                'idempotency_key' => $request->idempotencyKey,
            ],
        );

        if ($request->assignment !== null) {
            $input['assignment'] = $request->assignment;
            $input['agent_assignment_id'] = $request->assignment->getKey();
        } else {
            $input['assignment'] ??= null;
        }

        if ($request->execution !== null) {
            $input['execution'] = $request->execution;
            $input['agent_execution_id'] = $request->execution->getKey();
        } else {
            $input['execution'] ??= null;
        }

        if ($request->approval !== null) {
            $input['approval'] = $request->approval;
            $input['approval_request_id'] = $request->approval->getKey();
        } else {
            $input['approval'] ??= null;
        }

        if ($request->delegation !== null) {
            $input['delegation'] = $request->delegation;
        } else {
            $input['delegation'] ??= null;
        }

        try {
            return $this->capabilities->operation($definition->key)->execute($request->actor, $input);
        } catch (CapabilityExecutionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failure = app(FailureTranslator::class)->translate(
                $exception,
                correlationId: $request->resolvedCorrelationId(),
                provenance: new FailureProvenance(
                    operation: $definition->operation,
                    capability: $definition->key,
                ),
            );

            throw new CapabilityExecutionException($failure, $exception);
        }
    }

    private function dispatchRequested(CapabilityInvocationRequest $request, CapabilityDefinition $definition): void
    {
        if ($request->execution instanceof AgentExecution) {
            app(AgentExecutionEventService::class)->dispatch(
                CapabilityRequested::class,
                $request->execution,
                provenance: $this->provenance($request, $definition->operation),
                data: ['idempotency_key' => $request->idempotencyKey],
            );
        }
    }

    private function dispatchAuthorized(CapabilityInvocationRequest $request, CapabilityDefinition $definition): void
    {
        if ($request->execution instanceof AgentExecution) {
            app(AgentExecutionEventService::class)->dispatch(
                CapabilityAuthorized::class,
                $request->execution,
                provenance: $this->provenance($request, $definition->operation),
                data: [
                    'idempotency_key' => $request->idempotencyKey,
                    'approval_request_id' => $request->approval?->getKey(),
                ],
            );
        }
    }

    private function normalizeResult(mixed $result): mixed
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function provenance(CapabilityInvocationRequest $request, string $operation): array
    {
        return [
            'capability' => $request->capability,
            'operation' => $operation,
            'enterprise_id' => $request->enterprise?->getKey(),
            'agent_assignment_id' => $request->assignment?->getKey(),
            'agent_execution_id' => $request->execution?->getKey(),
            'correlation_id' => $request->resolvedCorrelationId(),
            'approval_request_id' => $request->approval?->getKey(),
        ];
    }
}