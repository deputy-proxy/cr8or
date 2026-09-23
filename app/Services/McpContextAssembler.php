<?php

namespace App\Services;

use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class McpContextAssembler
{
    /**
     * Assemble only the context categories explicitly required by the Agent.
     *
     * @param  list<string>  $requiredContext
     * @return array<string, mixed>
     */
    public function forAgent(User $user, Enterprise $enterprise, array $requiredContext): array
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $context = [
            'enterprise' => $this->enterprise($user, $enterprise->getKey()),
        ];

        foreach ($requiredContext as $requirement) {
            if ($requirement === 'enterprise' || array_key_exists($requirement, $context)) {
                continue;
            }

            $context[$requirement] = match ($requirement) {
                'knowledge' => $this->knowledge($user, $enterprise->getKey()),
                'strategy' => $this->strategy($user, $enterprise->getKey()),
                'work' => $this->work($user, $enterprise->getKey()),
                'financial' => $this->financial($user, $enterprise->getKey()),
                default => throw new \InvalidArgumentException(
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
        $context = $enterprise->context;

        return [
            'enterprise' => [
                'id' => $enterprise->getKey(),
                'name' => $enterprise->name,
                'slug' => $enterprise->slug,
                'status' => $enterprise->status,
            ],
            'context' => $context === null ? null : [
                'description' => $context->description,
                'industry' => $context->industry,
                'business_model' => $context->business_model,
                'target_market' => $context->target_market,
                'geography' => $context->geography,
                'additional_context' => $context->additional_context,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function strategy(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return [
            'enterprise' => $this->enterpriseReference($enterprise),
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
    public function knowledge(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return [
            'enterprise' => $this->enterpriseReference($enterprise),
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
    public function financial(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return app(FinancialReportingService::class)->context($enterprise);
    }

    /** @return array<string, mixed> */
    public function work(User $user, int $enterpriseId): array
    {
        $enterprise = $this->authorizedEnterprise($user, $enterpriseId);

        return [
            'enterprise' => $this->enterpriseReference($enterprise),
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

    private function authorizedEnterprise(User $user, int $enterpriseId): Enterprise
    {
        $enterprise = Enterprise::query()->findOrFail($enterpriseId);

        Gate::forUser($user)->authorize('view', $enterprise);

        return $enterprise;
    }

    /** @return array{id: int|string, name: string, slug: string, status: string} */
    private function enterpriseReference(Enterprise $enterprise): array
    {
        return [
            'id' => $enterprise->getKey(),
            'name' => $enterprise->name,
            'slug' => $enterprise->slug,
            'status' => $enterprise->status,
        ];
    }
}