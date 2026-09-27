<?php

namespace App\Contracts;

use App\Data\AgentContextSection;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;

interface AgentContextProvider
{
    /**
     * @return list<string>
     */
    public function requirements(): array;

    /**
     * @param  array<string, mixed>  $targetContext
     * @return list<AgentContextSection>
     */
    public function provide(
        User $user,
        Enterprise $enterprise,
        array $targetContext = [],
        ?AgentAssignment $assignment = null,
    ): array;
}