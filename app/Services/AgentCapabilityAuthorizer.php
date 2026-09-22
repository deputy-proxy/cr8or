<?php

namespace App\Services;

use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\Organization;

class AgentCapabilityAuthorizer
{
    public function allows(
        AgentAssignment $assignment,
        string $capability,
        ?Organization $organization = null,
        ?Enterprise $enterprise = null,
    ): bool {
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

        return $assignment->permissions()
            ->where('capability', $capability)
            ->exists();
    }
}
