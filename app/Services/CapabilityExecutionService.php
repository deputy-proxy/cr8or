<?php

namespace App\Services;

use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityRequest;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

final class CapabilityExecutionService
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly AgentCapabilityAuthorizer $authorizer,
        private readonly ApprovalRequestService $approvals,
    ) {}

    /**
     * Execute one already-structured Agent Capability request.
     *
     * Authorization is re-evaluated immediately before the Operation is invoked.
     * An approval-sensitive request without a valid approval creates a pending
     * approval and returns a resumable wait state instead of executing.
     *
     * @return array{status: 'executed'|'waiting', capability: string, result?: mixed, approval?: ApprovalRequest, provenance: array<string, mixed>}
     */
    public function execute(CapabilityRequest $request): array
    {
        $definition = $this->capabilities->resolve($request->capability);
        $permission = $request->assignment
            ->permissions()
            ->where('capability', $request->capability)
            ->first();

        if ($permission === null) {
            throw new AuthorizationException(
                "The Agent is not authorized for capability [{$request->capability}].",
            );
        }

        if (! $permission->requires_approval) {
            $this->authorize($request);

            return $this->invoke($request, $definition);
        }

        if ($request->approval === null) {
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

        $this->authorize($request);

        if (! $request->approval->isValid()) {
            throw new AuthorizationException(
                "Approval [{$request->approval->getKey()}] is not valid for capability [{$request->capability}].",
            );
        }

        $this->approvals->consume($request->approval, $request->execution);

        return $this->invoke($request, $definition);
    }

    private function authorize(CapabilityRequest $request): void
    {
        if (! $this->authorizer->allowsRequest($request)) {
            throw new AuthorizationException(
                "The Agent is not authorized for capability [{$request->capability}].",
            );
        }
    }

    /** @return array{status: 'executed', capability: string, result: mixed, provenance: array<string, mixed>} */
    private function invoke(CapabilityRequest $request, \App\Capabilities\CapabilityDefinition $definition): array
    {
        $enterprise = $request->assignment->enterprise;

        if (! $enterprise instanceof Enterprise) {
            throw new AuthorizationException('Capability execution requires an Enterprise-scoped Agent assignment.');
        }

        $input = array_merge(
            $request->inputPayload,
            $request->targetContext,
            [
                'enterprise' => $enterprise,
                'enterprise_id' => $enterprise->getKey(),
                'assignment' => $request->assignment,
                'agent_assignment_id' => $request->assignment->getKey(),
                'execution' => $request->execution,
                'agent_execution_id' => $request->execution->getKey(),

                'approval' => $request->approval,
                'approval_request_id' => $request->approval?->getKey(),
                'correlation_id' => $request->resolvedCorrelationId(),
                'delegation' => $request->delegation,
                'content_item' => null,
                'social_account' => null,

            ],
            [
                'content_item' => null,
                'social_account' => null,
            ],
        );

        try {
            $result = $this->capabilities->operation($definition->key)->execute($request->actor, $input);
        } catch (Throwable $exception) {
            throw $exception;
        }

        return [
            'status' => 'executed',
            'capability' => $definition->key,
            'result' => $this->normalizeResult($result),
            'provenance' => $this->provenance($request, $definition->operation),
        ];
    }

    /** @return array<string, mixed>|mixed */
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
    private function provenance(CapabilityRequest $request, string $operation): array
    {
        return [
            'capability' => $request->capability,
            'operation' => $operation,
            'agent_assignment_id' => $request->assignment->getKey(),
            'agent_execution_id' => $request->execution->getKey(),
            'enterprise_id' => $request->assignment->enterprise_id,
            'correlation_id' => $request->resolvedCorrelationId(),
            'approval' => $request->approval,
            'approval_request_id' => $request->approval?->getKey(),
        ];
    }
}