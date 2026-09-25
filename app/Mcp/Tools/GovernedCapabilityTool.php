<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityDefinition;
use App\Capabilities\CapabilityRegistry;
use App\Models\User;

abstract class GovernedCapabilityTool extends AuthorizedTool
{
    protected function definition(CapabilityRegistry $registry): CapabilityDefinition
    {
        return $registry->forTool(static::class);
    }

    protected function capability(CapabilityRegistry $registry): string
    {
        return $this->definition($registry)->key;
    }

    /** @param array<string, mixed> $input */
    protected function executeCapability(CapabilityRegistry $registry, User $actor, array $input): mixed
    {
        return $registry->operationForTool(static::class)->execute($actor, $input);
    }
}