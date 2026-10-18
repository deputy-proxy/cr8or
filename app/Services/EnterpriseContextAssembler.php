<?php

namespace App\Services;

use App\Data\AgentContext;
use App\Data\AgentContextSection;
use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class EnterpriseContextAssembler
{
    public function __construct(private readonly StrategicContextService $strategicContext) {}

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
                data: $this->context($user, $enterprise),
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
    private function context(User $user, Enterprise $enterprise): array
    {
        $context = $enterprise->context;

        return [
            'context' => $context === null ? null : [
                'id' => $context->getKey(),
                'description' => $context->description,
                'mission' => $context->mission,
                'vision' => $context->vision,
                'values' => $context->values ?? [],
                'industry' => $context->industry,
                'business_model' => $context->business_model,
                'target_market' => $context->target_market,
                'geography' => $context->geography,
                'additional_context' => $context->additional_context,
            ],
            'strategic_context' => array_merge($this->strategicContext->context($user, $enterprise), [

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
            ]),
            'products' => $enterprise->products()
                ->orderBy('id')
                ->get()
                ->map(fn (Product $product): array => [
                    'id' => $product->getKey(),
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'status' => $product->status,
                    'product_type' => $product->product_type,
                    'tagline' => $product->tagline,
                    'summary' => $product->summary,
                    'description' => $product->description,
                    'problem_statement' => $product->problem_statement,
                    'value_proposition' => $product->value_proposition,
                    'primary_outcome' => $product->primary_outcome,
                    'positioning' => $product->positioning,
                    'differentiators' => $product->differentiators,
                    'features' => $product->features,
                    'benefits' => $product->benefits,
                    'deliverables' => $product->deliverables,
                    'requirements' => $product->requirements,
                    'limitations' => $product->limitations,
                    'delivery_model' => $product->delivery_model,
                    'fulfillment_method' => $product->fulfillment_method,
                    'support_model' => $product->support_model,
                    'monetization_model' => $product->monetization_model,
                    'pricing_model' => $product->pricing_model,
                    'price_amount' => $product->price_amount,
                    'currency' => $product->currency,
                    'billing_interval' => $product->billing_interval,
                    'commercial_terms' => $product->commercial_terms,
                    'commercial_notes' => $product->commercial_notes,
                    'is_commercially_available' => $product->is_commercially_available,
                    'availability_start_at' => self::isoDateTime($product->getAttribute('availability_start_at')),
                    'availability_end_at' => self::isoDateTime($product->getAttribute('availability_end_at')),
                    'required_disclosures' => $product->required_disclosures,
                    'restrictions' => $product->restrictions,
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

    private static function isoDateTime(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? \Illuminate\Support\Carbon::instance($value)->toISOString() : null;
    }
}
