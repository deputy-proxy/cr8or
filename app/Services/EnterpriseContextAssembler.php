<?php

namespace App\Services;

use App\Data\AgentContext;
use App\Data\AgentContextSection;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class EnterpriseContextAssembler
{
    public function assemble(User $user, Enterprise $enterprise): AgentContext
    {
        Gate::forUser($user)->authorize('view', $enterprise);

        $scope = [
            'organization_id' => $enterprise->organization_id,
            'enterprise_id' => $enterprise->getKey(),
        ];

        return (new AgentContext)
            ->withSection(new AgentContextSection(
                name: 'enterprise',
                data: $this->identity($enterprise),
                source: Enterprise::class,
                scope: $scope,
                relevance: 'Agent execution scope',
            ))
            ->withSection(new AgentContextSection(
                name: 'enterprise_context',
                data: $this->context($enterprise),
                source: EnterpriseContext::class,
                scope: $scope,
                relevance: 'Enterprise business context',
            ));
    }

    /** @return array{id: int|string, name: string, slug: string, status: string} */
    private function identity(Enterprise $enterprise): array
    {
        return [
            'id' => $enterprise->getKey(),
            'name' => $enterprise->name,
            'slug' => $enterprise->slug,
            'status' => $enterprise->status,
        ];
    }

    /** @return array<string, mixed> */
    private function context(Enterprise $enterprise): array
    {
        $context = $enterprise->context;

        return [
            'context' => $context === null ? null : [
                'description' => $context->description,
                'industry' => $context->industry,
                'business_model' => $context->business_model,
                'target_market' => $context->target_market,
                'geography' => $context->geography,
                'additional_context' => $context->additional_context,
            ],
            'strategic_context' => [
                'goals' => $enterprise->goals()
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($goal) => [
                        'id' => $goal->getKey(),
                        'name' => $goal->name,
                        'description' => $goal->description,
                        'status' => $goal->status,
                    ])
                    ->all(),
                'kpis' => $enterprise->kpis()
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($kpi) => [
                        'id' => $kpi->getKey(),
                        'name' => $kpi->name,
                        'definition' => $kpi->definition,
                        'unit' => $kpi->unit,
                        'target_value' => $kpi->target_value,
                        'current_value' => $kpi->current_value,
                        'status' => $kpi->status,
                    ])
                    ->all(),
            ],
            'products' => $enterprise->products()
                ->orderBy('id')
                ->get()
                ->map(fn ($product) => [
                    'id' => $product->getKey(),
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'status' => $product->status,
                ])
                ->all(),
            'customers' => $enterprise->customers()
                ->orderBy('id')
                ->get()
                ->map(fn ($customer) => [
                    'id' => $customer->getKey(),
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'status' => $customer->status,
                ])
                ->all(),
            'partners' => $enterprise->partners()
                ->orderBy('id')
                ->get()
                ->map(fn ($partner) => [
                    'id' => $partner->getKey(),
                    'name' => $partner->name,
                    'email' => $partner->email,
                    'phone' => $partner->phone,
                    'status' => $partner->status,
                ])
                ->all(),
        ];
    }
}
