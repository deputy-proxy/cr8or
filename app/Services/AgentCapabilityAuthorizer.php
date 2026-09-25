<?php

namespace App\Services;

use App\Capabilities\CapabilityRegistry;
use App\Experts\Expert;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\Organization;
use App\Models\User;

class AgentCapabilityAuthorizer
{
    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    /**
     * Authorize a Capability requested through an Expert runtime.
     *
     * The Expert may declare a Capability, but that declaration never grants
     * authority. The Agent assignment permission remains the authoritative
     * authorization boundary.
     *
     * @param  array<string, mixed>  $targetContext
     */
    public function allowsExpertCapability(
        AgentAssignment $assignment,
        Expert $expert,
        string $capability,
        ?Organization $organization = null,
        ?Enterprise $enterprise = null,
        ?User $actor = null,
        ?ApprovalRequest $approval = null,
        ?AgentExecution $execution = null,
        array $targetContext = [],
        ?AgentDelegation $delegation = null,
    ): bool {
        if (! in_array($capability, $expert->capabilities(), true)) {
            return false;
        }

        return $this->allows(
            $assignment,
            $capability,
            $organization,
            $enterprise,
            $actor,
            $approval,
            $execution,
            $targetContext,
            $delegation,
        );
    }

    /** @param array<string, mixed> $targetContext */
    public function allows(
        AgentAssignment $assignment,
        string $capability,
        ?Organization $organization = null,
        ?Enterprise $enterprise = null,
        ?User $actor = null,
        ?ApprovalRequest $approval = null,
        ?AgentExecution $execution = null,
        array $targetContext = [],
        ?AgentDelegation $delegation = null,
    ): bool {
        try {
            $this->capabilities->resolve($capability);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            return false;
        }
        if ($organization !== null && $organization->getKey() !== $assignment->organization_id) {
            return false;
        }
        if ($enterprise !== null) {
            if ($enterprise->organization_id !== $assignment->organization_id) {
                return false;
            }
            if ($assignment->enterprise_id !== null && $enterprise->getKey() !== $assignment->enterprise_id) {
                return false;
            }
        }
        if ($assignment->enterprise_id !== null && $enterprise === null) {
            return false;
        }

        $permission = $assignment->permissions()->where('capability', $capability)->first();
        if ($permission === null) {
            return false;
        }
        if (! $permission->requires_approval) {
            return true;
        }
        if ($actor === null || $approval === null) {
            return false;
        }

        return app(ApprovalRequestService::class)->matches(
            $approval,
            $actor,
            $assignment,
            $capability,
            $execution,
            $targetContext,
            $delegation,
        );
    }
}
