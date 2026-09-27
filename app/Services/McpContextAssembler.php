<?php

namespace App\Services;

use App\Data\AgentContext;
use App\Data\AgentContextSection;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

class McpContextAssembler
{
    private const STRATEGY_CONTEXT_LIMIT = 100;

    public function __construct(
        private readonly EnterpriseContextAssembler $enterpriseContextAssembler,
        private readonly WorkContextAssembler $workContextAssembler,
    ) {}

    /**
     * Assemble only the context categories explicitly required by the Agent.
     *
     * @param  list<string>  $requiredContext
     */
    public function forAgent(User $user, Enterprise $enterprise, array $requiredContext): AgentContext
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $context = $this->enterpriseContextAssembler->assemble($user, $enterprise);
        $scope = $this->scope($enterprise);

        foreach ($requiredContext as $requirement) {
            if ($requirement === 'enterprise' || $requirement === 'enterprise_context') {
                continue;
            }

            $context = match ($requirement) {
                'knowledge' => $context->withSection($this->knowledgeSection($user, $enterprise, $scope)),
                'strategy' => $context->withSection($this->strategySection($user, $enterprise, $scope)),
                'work' => $context->withSection($this->workSection($user, $enterprise, $scope)),
                'financial' => $context->withSection(new AgentContextSection(
                    name: 'financial',
                    data: $this->financial($user, $enterprise->getKey()),
                    source: FinancialReportingService::class,
                    scope: $scope,
                    relevance: 'Agent-declared context requirement',
                )),
                default => throw new InvalidArgumentException(
                    "Agent requires unsupported context category [{$requirement}].",
                ),
            };
        }

        return $context;
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

        return $this->strategyData($enterprise);
    }

    /** @return array<string, mixed> */
    public function knowledge(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return $this->knowledgeData($enterprise);
    }

    /** @return array<string, mixed> */
    public function financial(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return app(FinancialReportingService::class)->context($enterprise);
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

    /** @param array{organization_id: int|string, enterprise_id: int|string} $scope */
    private function strategySection(User $user, Enterprise $enterprise, array $scope): AgentContextSection
    {
        $authorized = $this->authorizedEnterprise($user, $enterprise->getKey());

        return new AgentContextSection(
            name: 'strategy',
            data: $this->strategyData($authorized),
            source: 'Enterprise::objectives()->strategies()->plans()->initiatives()',
            scope: $scope,
            relevance: 'Agent-declared context requirement',
        );
    }

    /** @param array{organization_id: int|string, enterprise_id: int|string} $scope */
    private function knowledgeSection(User $user, Enterprise $enterprise, array $scope): AgentContextSection
    {
        $authorized = $this->authorizedEnterprise($user, $enterprise->getKey());

        return new AgentContextSection(
            name: 'knowledge',
            data: $this->knowledgeData($authorized),
            source: 'Enterprise::knowledgeContexts()/knowledgeItems()',
            scope: $scope,
            relevance: 'Agent-declared context requirement',
        );
    }

    /** @param array{organization_id: int|string, enterprise_id: int|string} $scope */
    private function workSection(User $user, Enterprise $enterprise, array $scope): AgentContextSection
    {
        $authorized = $this->authorizedEnterprise($user, $enterprise->getKey());

        return new AgentContextSection(
            name: 'work',
            data: $this->workContextAssembler->assemble($user, $authorized),
            source: WorkContextAssembler::class,
            scope: $scope,
            relevance: 'Agent-declared context requirement',
        );
    }

    /** @return array<string, mixed> */
    private function strategyData(Enterprise $enterprise): array
    {
        $goals = $enterprise->goals()
            ->orderBy('id')
            ->limit(self::STRATEGY_CONTEXT_LIMIT)
            ->get();

        $kpis = $enterprise->kpis()
            ->orderBy('id')
            ->limit(self::STRATEGY_CONTEXT_LIMIT)
            ->get();

        $goalById = $goals->keyBy('id');
        $kpiById = $kpis->keyBy('id');

        $objectives = $enterprise->objectives()
            ->orderBy('id')
            ->limit(self::STRATEGY_CONTEXT_LIMIT)
            ->get()
            ->map(function ($objective) use ($goalById, $kpiById) {
                $goal = $goalById->get($objective->goal_id);
                $kpi = $kpiById->get($objective->kpi_id);

                return [
                    'id' => $objective->getKey(),
                    'name' => $objective->name,
                    'description' => $objective->description,
                    'goal' => $goal === null ? null : [
                        'id' => $goal->getKey(),
                        'name' => $goal->name,
                        'description' => $goal->description,
                        'status' => $goal->status,
                    ],
                    'kpi' => $kpi === null ? null : [
                        'id' => $kpi->getKey(),
                        'name' => $kpi->name,
                        'definition' => $kpi->definition,
                        'unit' => $kpi->unit,
                        'target_value' => $kpi->target_value,
                        'current_value' => $kpi->current_value,
                        'status' => $kpi->status,
                    ],
                    'strategies' => $objective->strategies()
                        ->orderBy('id')
                        ->limit(self::STRATEGY_CONTEXT_LIMIT)
                        ->get()
                        ->map(fn ($strategy) => [
                            'id' => $strategy->getKey(),
                            'name' => $strategy->name,
                            'description' => $strategy->description,
                            'plans' => $strategy->plans()
                                ->orderBy('id')
                                ->limit(self::STRATEGY_CONTEXT_LIMIT)
                                ->get()
                                ->map(fn ($plan) => [
                                    'id' => $plan->getKey(),
                                    'name' => $plan->name,
                                    'description' => $plan->description,
                                    'initiatives' => $plan->initiatives()
                                        ->orderBy('id')
                                        ->limit(self::STRATEGY_CONTEXT_LIMIT)
                                        ->get()
                                        ->map(fn ($initiative) => [
                                            'id' => $initiative->getKey(),
                                            'name' => $initiative->name,
                                            'description' => $initiative->description,
                                        ])
                                        ->all(),
                                ])
                                ->all(),
                        ])
                        ->all(),
                ];
            })
            ->all();

        return [
            'enterprise' => $this->enterpriseIdentity($enterprise),
            'goals' => $goals->map(fn ($goal) => [
                'id' => $goal->getKey(),
                'name' => $goal->name,
                'description' => $goal->description,
                'status' => $goal->status,
            ])->all(),
            'kpis' => $kpis->map(fn ($kpi) => [
                'id' => $kpi->getKey(),
                'name' => $kpi->name,
                'definition' => $kpi->definition,
                'unit' => $kpi->unit,
                'target_value' => $kpi->target_value,
                'current_value' => $kpi->current_value,
                'status' => $kpi->status,
            ])->all(),
            'objectives' => $objectives,
        ];
    }

    /** @return array<string, mixed> */
    private function knowledgeData(Enterprise $enterprise): array
    {
        return [
            'enterprise' => $this->enterpriseIdentity($enterprise),
            'contexts' => $enterprise->knowledgeContexts()
                ->orderBy('id')
                ->get()
                ->map(fn ($context) => [
                    'id' => $context->getKey(),
                    'name' => $context->name,
                    'type' => $context->type,
                    'description' => $context->description,
                    'data' => $context->data,
                ])
                ->all(),
            'items' => $enterprise->knowledgeItems()
                ->orderBy('id')
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->getKey(),
                    'title' => $item->title,
                    'type' => $item->type,
                    'summary' => $item->summary,
                ])
                ->all(),
        ];
    }

    /** @return array{id: int|string, name: string, slug: string, status: string} */
    private function enterpriseIdentity(Enterprise $enterprise): array
    {
        return [
            'id' => $enterprise->getKey(),
            'name' => $enterprise->name,
            'slug' => $enterprise->slug,
            'status' => $enterprise->status,
        ];
    }

    /** @return array{organization_id: int|string, enterprise_id: int|string} */
    private function scope(Enterprise $enterprise): array
    {
        return [
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->getKey(),
        ];
    }
}