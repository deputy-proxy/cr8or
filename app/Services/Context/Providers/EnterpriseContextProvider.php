<?php

namespace App\Services\Context\Providers;

use App\Contracts\AgentContextProvider;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\EnterpriseContextAssembler;

final class EnterpriseContextProvider implements AgentContextProvider
{
    public function __construct(
        private readonly EnterpriseContextAssembler $assembler,
    ) {}

    public function requirements(): array
    {
        return ['enterprise', 'enterprise_context'];
    }

    public function provide(User $user, Enterprise $enterprise, array $targetContext = [], ?AgentAssignment $assignment = null): array
    {
        return $this->assembler->assemble($user, $enterprise)->sections();
    }
}