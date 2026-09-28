<?php

namespace App\Services;

use App\Data\CapabilityInvocationRequest;
use App\Data\CapabilityRequest;
use App\Models\Enterprise;
use Illuminate\Auth\Access\AuthorizationException;

final class CapabilityExecutionService
{
    public function __construct(private readonly CapabilityInvocationService $invocations) {}

    /**
     * Execute the canonical Agent Capability request through the application boundary.
     *
     * @return array{status: 'executed'|'waiting', capability: string, result?: mixed, approval?: \App\Models\ApprovalRequest, provenance: array<string, mixed>}
     */
    public function execute(CapabilityRequest $request): array
    {
        $enterprise = $request->assignment->enterprise;

        if (! $enterprise instanceof Enterprise) {
            throw new AuthorizationException(
                'Capability execution requires an Enterprise-scoped Agent assignment.',
            );
        }

        return $this->invocations->invoke(new CapabilityInvocationRequest(
            capability: $request->capability,
            actor: $request->actor,
            enterprise: $enterprise,
            targetContext: $request->targetContext,
            inputPayload: $request->inputPayload,
            assignment: $request->assignment,
            execution: $request->execution,
            approval: $request->approval,
            delegation: $request->delegation,
            correlationId: $request->resolvedCorrelationId(),
            idempotencyKey: $request->idempotencyKey,
            expertSlug: $request->expertSlug,
        ));
    }
}