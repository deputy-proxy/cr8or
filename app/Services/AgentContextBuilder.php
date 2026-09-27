<?php

namespace App\Services;

use App\Contracts\AgentContextProvider;
use App\Data\AgentContext;
use App\Models\AgentAssignment;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\Context\Providers\DecisionContextProvider;
use App\Services\Context\Providers\EnterpriseContextProvider;
use App\Services\Context\Providers\ExecutionHistoryContextProvider;
use App\Services\Context\Providers\FinancialContextProvider;
use App\Services\Context\Providers\KnowledgeContextProvider;
use App\Services\Context\Providers\MemoryContextProvider;
use App\Services\Context\Providers\StrategyContextProvider;
use App\Services\Context\Providers\WorkContextProvider;
use InvalidArgumentException;

final class AgentContextBuilder
{
    /** @var array<string, AgentContextProvider> */
    private array $providers = [];

    public function __construct(
        EnterpriseContextProvider $enterprise,
        StrategyContextProvider $strategy,
        WorkContextProvider $work,
        KnowledgeContextProvider $knowledge,
        MemoryContextProvider $memory,
        DecisionContextProvider $decisions,
        ExecutionHistoryContextProvider $executionHistory,
        FinancialContextProvider $financial,
    ) {
        foreach ([$enterprise, $strategy, $work, $knowledge, $memory, $decisions, $executionHistory, $financial] as $provider) {
            foreach ($provider->requirements() as $requirement) {
                if (isset($this->providers[$requirement]) && $this->providers[$requirement] !== $provider) {
                    throw new InvalidArgumentException("Multiple Agent context providers registered for [{$requirement}].");
                }

                $this->providers[$requirement] = $provider;
            }
        }
    }

    /**
     * @param  list<string>  $requiredContext
     * @param  array<string, mixed>  $targetContext
     */
    public function build(
        User $user,
        Enterprise $enterprise,
        array $requiredContext,
        array $targetContext = [],
        ?AgentAssignment $assignment = null,
    ): AgentContext {
        $context = new AgentContext;
        $provided = [];

        // Enterprise identity/context is always the base execution scope.
        $context = $this->appendProvider(
            $context,
            $this->provider('enterprise'),
            $user,
            $enterprise,
            $targetContext,
            $assignment,
            $provided,
        );

        foreach (array_values(array_unique($requiredContext)) as $requirement) {
            $provider = $this->providers[$requirement] ?? null;

            if ($provider === null) {
                throw new InvalidArgumentException(
                    "Agent requires unsupported context category [{$requirement}].",
                );
            }

            $context = $this->appendProvider(
                $context,
                $provider,
                $user,
                $enterprise,
                $targetContext,
                $assignment,
                $provided,
            );
        }

        return $context;
    }

    private function provider(string $requirement): AgentContextProvider
    {
        return $this->providers[$requirement]
            ?? throw new InvalidArgumentException("Agent context provider [{$requirement}] is not registered.");
    }

    /**
     * @param  array<string, bool>  $provided
     * @param  array<string, mixed>  $targetContext
     */
    private function appendProvider(
        AgentContext $context,
        AgentContextProvider $provider,
        User $user,
        Enterprise $enterprise,
        array $targetContext,
        ?AgentAssignment $assignment,
        array &$provided,
    ): AgentContext {
        $key = $provider::class;

        if (isset($provided[$key])) {
            return $context;
        }

        $provided[$key] = true;

        foreach ($provider->provide($user, $enterprise, $targetContext, $assignment) as $section) {
            $context = $context->withSection($section);
        }

        return $context;
    }
}
