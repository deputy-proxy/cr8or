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
    public function __construct(
        private readonly EnterpriseContextAssembler $enterpriseContextAssembler,
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

        return $this->workData($enterprise);
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
            source: 'Enterprise::objectives()->strategies',
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
            data: $this->workData($authorized),
            source: 'Enterprise::workItems()',
            scope: $scope,
            relevance: 'Agent-declared context requirement',
        );
    }

    /** @return array<string, mixed> */
    private function strategyData(Enterprise $enterprise): array
    {
        return [
            'enterprise' => $this->enterpriseIdentity($enterprise),
            'strategies' => $enterprise->objectives()
                ->with('strategies')
                ->get()
                ->flatMap(fn ($objective) => $objective->strategies->map(fn ($strategy) => [
                    'id' => $strategy->getKey(),
                    'name' => $strategy->name,
                    'description' => $strategy->description,
                    'objective' => [
                        'id' => $objective->getKey(),
                        'name' => $objective->name,
                    ],
                ]))
                ->values()
                ->all(),
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

    /** @return array<string, mixed> */
    private function workData(Enterprise $enterprise): array
    {
        return [
            'enterprise' => $this->enterpriseIdentity($enterprise),
            'work_items' => $enterprise->workItems()
                ->with('project')
                ->orderBy('id')
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->getKey(),
                    'name' => $item->name,
                    'description' => $item->description,
                    'status' => $item->status,
                    'project' => $item->project === null ? null : [
                        'id' => $item->project->getKey(),
                        'name' => $item->project->name,
                    ],
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