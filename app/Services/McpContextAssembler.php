<?php

namespace App\Services;

use App\Data\AgentContext;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class McpContextAssembler
{
    public function __construct(
        private readonly AgentContextBuilder $contextBuilder,
        private readonly EnterpriseContextAssembler $enterpriseContextAssembler,
        private readonly StrategyContextAssembler $strategyContextAssembler,
        private readonly KnowledgeContextAssembler $knowledgeContextAssembler,
        private readonly WorkContextAssembler $workContextAssembler,
        private readonly FinancialReportingService $financialReportingService,
    ) {}

    /**
     * Assemble only the context categories explicitly required by the Agent.
     *
     * @param  list<string>  $requiredContext
     * @param  array<string, mixed>  $targetContext
     */
    public function forAgent(
        User $user,
        Enterprise $enterprise,
        array $requiredContext,
        array $targetContext = [],
        ?AgentAssignment $assignment = null,
    ): AgentContext {
        return $this->contextBuilder->build(
            $user,
            $enterprise,
            $requiredContext,
            $targetContext,
            $assignment,
        );
    }

    /** @return array<string, mixed> */
    public function enterprise(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);
        $context = $this->enterpriseContextAssembler->assemble($user, $enterprise);

        return [
            'enterprise' => $context->section('enterprise')?->data,
            'context' => $context->section('enterprise_context')?->data['context'],
        ];
    }

    /** @return array<string, mixed> */
    public function strategy(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return $this->strategyContextAssembler->assemble($user, $enterprise);
    }

    /** @return array<string, mixed> */
    public function knowledge(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return $this->knowledgeContextAssembler->assemble($user, $enterprise);
    }

    /** @return array<string, mixed> */
    public function financial(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return $this->financialReportingService->context($enterprise);
    }

    /** @return array<string, mixed> */
    public function work(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return $this->workContextAssembler->assemble($user, $enterprise);
    }

    private function authorizedEnterprise(User $user, int $enterpriseId): Enterprise
    {
        $enterprise = Enterprise::query()->findOrFail($enterpriseId);

        Gate::forUser($user)->authorize('view', $enterprise);

        return $enterprise;
    }
}