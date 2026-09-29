<?php

namespace App\Services;

use App\Agents\Agent;
use App\Capabilities\CapabilityRegistry;
use App\Data\CapabilityRequest;
use App\Experts\Expert;
use App\Models\AgentAssignment;
use App\Models\AgentDelegation;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\Enterprise;
use App\Models\ExpertDescriptor;
use App\Models\Organization;
use App\Models\User;

class AgentCapabilityAuthorizer
{
    public function __construct(private readonly CapabilityRegistry $capabilities) {}

    /** Authorize a Capability request through its canonical contract. */
    public function allowsRequest(CapabilityRequest $request, bool $allowPendingApproval = false): bool
    {
        if ($request->expertSlug !== null) {
            return $this->allowsExpertRequest($request, $allowPendingApproval);
        }

        return $this->allows(
            $request->assignment,
            $request->capability,
            $request->assignment->organization,
            $request->assignment->enterprise,
            $request->actor,
            $request->approval,
            $request->execution,
            $request->targetContext,
            $request->delegation,
            $allowPendingApproval,
        );
    }

    /**
     * Authorize a Capability requested through an Expert runtime.
     *
     * Expert ownership is the Capability authorization boundary after the
     * Agent has been authorized to invoke that Expert. Direct Agent Capability
     * authorization remains available through allows().
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
        bool $allowPendingApproval = false,
    ): bool {
        if (! in_array($capability, $expert->capabilities(), true)) {
            return false;
        }

        try {
            $definition = $this->capabilities->resolve($capability);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (! $assignment->enabled || ! $assignment->agentDescriptor->enabled) {
            return false;
        }
        if ($organization !== null && $organization->getKey() !== $assignment->organization_id) {
            return false;
        }
        if ($enterprise !== null && ($enterprise->organization_id !== $assignment->organization_id
            || ($assignment->enterprise_id !== null && $enterprise->getKey() !== $assignment->enterprise_id))) {
            return false;
        }
        if ($assignment->enterprise_id !== null && $enterprise === null) {
            return false;
        }

        if ($definition->approvalRequirement === 'required') {
            if ($approval === null) {
                return $allowPendingApproval;
            }
            if ($actor === null) {
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

        return true;
    }

    private function allowsExpertRequest(CapabilityRequest $request, bool $allowPendingApproval): bool
    {
        $descriptor = ExpertDescriptor::query()->where('slug', $request->expertSlug)->first();
        if ($descriptor === null || ! $descriptor->enabled) {
            return false;
        }

        $runtime = app($descriptor->resolveRuntimeClass());
        if (! $runtime instanceof \App\Experts\Expert) {
            return false;
        }

        if (! $this->agentAllowsExpert($request->assignment, $request->expertSlug)) {
            return false;
        }

        return $this->allowsExpertCapability(
            $request->assignment,
            $runtime,
            $request->capability,
            $request->assignment->organization,
            $request->assignment->enterprise,
            $request->actor,
            $request->approval,
            $request->execution,
            $request->targetContext,
            $request->delegation,
            $allowPendingApproval,
        );
    }

    public function agentAllowsExpert(AgentAssignment $assignment, string $expertSlug): bool
    {
        $runtimeClass = $assignment->agentDescriptor->resolveRuntimeClass();
        if (! class_exists($runtimeClass) || (new \ReflectionClass($runtimeClass))->isAbstract()) {
            return false;
        }

        $runtime = app($runtimeClass);

        return $runtime instanceof Agent && in_array($expertSlug, $runtime->experts(), true);
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
        bool $allowPendingApproval = false,
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
            return $allowPendingApproval;
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