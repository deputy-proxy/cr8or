<?php

namespace App\Policies;

use App\Data\CapabilityExecutionContext;
use App\Enums\CapabilityExecutionMode;
use App\Models\Asset;
use App\Models\Enterprise;
use App\Models\Script;
use App\Models\User;
use App\Policies\Concerns\HasExplicitCrudContract;

class AssetPolicy
{
    use HasExplicitCrudContract;

    public function view(User $u, Asset $a): bool
    {
        return (new EnterprisePolicy)->view($u, $a->enterprise);
    }

    public function create(User $u): bool
    {
        return (new EnterprisePolicy)->create($u);
    }

    public function createForScript(
        User $u,
        Script $script,
        ?CapabilityExecutionContext $context = null,
    ): bool {
        $enterprise = $script->contentItem->enterprise;

        if ($context?->mode === CapabilityExecutionMode::WORKFLOW) {
            return $context->workflowExecution?->actor_id === $u->getKey()
                && $context->workflowExecution?->enterprise_id === $enterprise->getKey()
                && $context->workflowExecution?->workflow_id === $context->workflowStage?->workflow_id
                && (new EnterprisePolicy)->view($u, $enterprise);
        }

        return (new EnterprisePolicy)->createForOrganization($u, $enterprise->organization)
            && $script->agent_assignment_id !== null
            && $script->agent_execution_id !== null;
    }

    public function createForEnterprise(User $u, Enterprise $e): bool
    {
        return (new EnterprisePolicy)->createForOrganization($u, $e->organization);
    }

    public function update(User $u, Asset $a): bool
    {
        return (new EnterprisePolicy)->update($u, $a->enterprise);
    }

    public function delete(User $u, Asset $a): bool
    {
        return (new EnterprisePolicy)->delete($u, $a->enterprise);
    }
}