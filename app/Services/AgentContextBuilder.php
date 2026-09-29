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
use App\Services\Context\Providers\ReportingContextProvider;
use App\Services\Context\Providers\RetrievedKnowledgeContextProvider;
use App\Services\Context\Providers\StrategyContextProvider;
use App\Services\Context\Providers\WorkContextProvider;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final class AgentContextBuilder
{
    /** @var array<string, AgentContextProvider> */
    private array $providers = [];

    private readonly DiagnosticSanitizer $sanitizer;

    public function __construct(
        EnterpriseContextProvider $enterprise,
        StrategyContextProvider $strategy,
        WorkContextProvider $work,
        DiagnosticSanitizer $sanitizer,
        KnowledgeContextProvider $knowledge,
        RetrievedKnowledgeContextProvider $retrievedKnowledge,
        MemoryContextProvider $memory,
        DecisionContextProvider $decisions,
        ExecutionHistoryContextProvider $executionHistory,
        FinancialContextProvider $financial,
        ReportingContextProvider $reporting,
    ) {
        $this->sanitizer = $sanitizer;

        foreach ([$enterprise, $strategy, $work, $knowledge, $retrievedKnowledge, $memory, $decisions, $executionHistory, $financial, $reporting] as $provider) {
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

        try {
            foreach ($provider->provide($user, $enterprise, $targetContext, $assignment) as $section) {
                $context = $context->withSection($section);
            }
        } catch (\Throwable $exception) {
            Log::error('CR8OR Agent context provider failed.', [
                'provider' => $provider::class,
                'requirements' => $provider->requirements(),
                'enterprise_id' => $enterprise->getKey(),
                'organization_id' => $enterprise->organization_id,
                'agent_assignment_id' => $assignment?->getKey(),
                'exception' => $exception::class,
                'message' => $this->sanitizer->message($exception->getMessage()),
            ]);

            throw $exception;
        }

        return $context;
    }
}