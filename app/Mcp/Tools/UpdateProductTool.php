<?php

namespace App\Mcp\Tools;

use App\Domain\Products\ProductInputRules;
use App\Enums\ProductBillingInterval;
use App\Enums\ProductDeliveryModel;
use App\Enums\ProductMonetizationModel;
use App\Enums\ProductPricingModel;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Enterprise;
use App\Models\Product;
use App\Models\User;
use App\Services\DomainResourceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-product')]
#[Description('Update an existing enterprise-owned product in place. Only supplied mutable fields change; product and enterprise identity cannot be changed. The product update runs through the governed product.update capability.')]
final class UpdateProductTool extends DomainMutationTool
{
    /** @return array<string, mixed> */
    protected static function schemaFields(JsonSchema $schema): array
    {
        $fields = [
            'product_id' => $schema->integer()->min(1)->required()->description('Existing product ID to update.'),
            'name' => $schema->string()->min(1)->max(255),
            'slug' => $schema->string()->min(1)->max(255)->pattern('^[A-Za-z0-9_-]+$'),
            'status' => $schema->string()->enum(array_column(ProductStatus::cases(), 'value')),
            'product_type' => $schema->string()->enum(array_column(ProductType::cases(), 'value')),
            'tagline' => $schema->string()->nullable()->max(255),
            'summary' => $schema->string()->nullable()->max(1000),
            'description' => $schema->string()->nullable()->max(50000),
            'problem_statement' => $schema->string()->nullable()->max(10000),
            'value_proposition' => $schema->string()->nullable()->max(10000),
            'primary_outcome' => $schema->string()->nullable()->max(5000),
            'positioning' => $schema->string()->nullable()->max(10000),
            'delivery_model' => $schema->string()->enum(array_column(ProductDeliveryModel::cases(), 'value')),
            'fulfillment_method' => $schema->string()->nullable()->max(255),
            'support_model' => $schema->string()->nullable()->max(5000),
            'monetization_model' => $schema->string()->enum(array_column(ProductMonetizationModel::cases(), 'value')),
            'pricing_model' => $schema->string()->enum(array_column(ProductPricingModel::cases(), 'value')),
            'price_amount' => $schema->number()->nullable()->min(0)->max(9999999999.99)->multipleOf(0.01),
            'currency' => $schema->string()->nullable()->min(3)->max(3)->pattern('^[A-Za-z]{3}$'),
            'billing_interval' => $schema->string()->nullable()->enum(array_column(ProductBillingInterval::cases(), 'value')),
            'commercial_terms' => $schema->string()->nullable()->max(10000),
            'commercial_notes' => $schema->string()->nullable()->max(10000),
            'is_commercially_available' => $schema->boolean(),
            'availability_start_at' => $schema->string()->nullable()->format('date-time'),
            'availability_end_at' => $schema->string()->nullable()->format('date-time'),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];

        foreach (Product::STRUCTURED_LIST_FIELDS as $field) {
            $fields[$field] = $schema->array()->nullable()->items($schema->string()->min(1)->max(500))->max(50);
        }

        return $fields;
    }

    /** @return array<string, array<int, mixed>> */
    protected static function rules(): array
    {
        $productRules = ProductInputRules::rules();
        $productRules['name'] = ['sometimes', 'string', 'min:1', 'max:255'];
        $productRules['slug'] = ['sometimes', 'string', 'min:1', 'max:255', 'alpha_dash'];

        return array_merge([
            'product_id' => ['required', 'integer', 'min:1', 'exists:products,id'],
            'id' => ['prohibited'],
            'enterprise_id' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
            'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
            'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
        ], $productRules);
    }

    /** @param array<string, mixed> $validated */
    protected static function target(array $validated): Product
    {
        return Product::query()->with('enterprise')->findOrFail((int) $validated['product_id']);
    }

    /** @param array<string, mixed> $validated */
    protected static function enterprise(array $validated): Enterprise
    {
        $target = self::target($validated);

        return $target->enterprise;
    }

    /** @param array<string, mixed> $validated @return array<string, mixed> */
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['product_id' => $target?->getKey()];
    }

    /** @param array<string, mixed> $validated @return array{0: string, 1: mixed} */
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['update', $target];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        if (! $target instanceof Product) {
            throw new \LogicException('Unexpected product target.');
        }

        $attributes = array_intersect_key($validated, array_flip(array_merge(
            ['name', 'slug', 'status', 'product_type', 'tagline', 'summary', 'description', 'problem_statement',
                'value_proposition', 'primary_outcome', 'positioning', 'delivery_model', 'fulfillment_method',
                'support_model', 'monetization_model', 'pricing_model', 'price_amount', 'currency', 'billing_interval',
                'commercial_terms', 'commercial_notes', 'is_commercially_available', 'availability_start_at', 'availability_end_at'],
            Product::STRUCTURED_LIST_FIELDS,
        )));
        if ($attributes === []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['product_id' => 'At least one mutable product field must be supplied.']);
        }

        return $domain->updateProduct($actor, $target, $attributes);
    }

    /** @return array<string, mixed> */
    protected static function result(Model $record): array
    {
        if (! $record instanceof Product) {
            throw new \LogicException('Unexpected product result.');
        }

        return [
            'id' => $record->id,
            'enterprise_id' => $record->enterprise_id,
            'name' => $record->name,
            'slug' => $record->slug,
            'status' => $record->status,
            'product_type' => $record->product_type,
            'tagline' => $record->tagline,
            'summary' => $record->summary,
            'description' => $record->description,
            'problem_statement' => $record->problem_statement,
            'value_proposition' => $record->value_proposition,
            'primary_outcome' => $record->primary_outcome,
            'positioning' => $record->positioning,
            'differentiators' => $record->differentiators,
            'features' => $record->features,
            'benefits' => $record->benefits,
            'deliverables' => $record->deliverables,
            'requirements' => $record->requirements,
            'limitations' => $record->limitations,
            'delivery_model' => $record->delivery_model,
            'fulfillment_method' => $record->fulfillment_method,
            'support_model' => $record->support_model,
            'monetization_model' => $record->monetization_model,
            'pricing_model' => $record->pricing_model,
            'price_amount' => $record->price_amount === null ? null : (float) $record->price_amount,
            'currency' => $record->currency,
            'billing_interval' => $record->billing_interval,
            'commercial_terms' => $record->commercial_terms,
            'commercial_notes' => $record->commercial_notes,
            'is_commercially_available' => (bool) $record->is_commercially_available,
            'availability_start_at' => $record->availability_start_at?->toISOString(),
            'availability_end_at' => $record->availability_end_at?->toISOString(),
            'required_disclosures' => $record->required_disclosures,
            'restrictions' => $record->restrictions,
        ];
    }
}
