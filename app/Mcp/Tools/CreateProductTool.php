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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-product')]
#[Description('Create a complete enterprise-scoped product offering, including positioning, delivery, one inline commercial configuration, and disclosures. All fields except enterprise_id and name are optional; omitted product fields use documented defaults or remain null. Product rules and persistence run through the governed product.create capability.')]
class CreateProductTool extends DomainMutationTool
{
    protected static function schemaFields(JsonSchema $schema): array
    {
        $fields = [
            'enterprise_id' => $schema->integer()->min(1)->description('Target Enterprise ID; authorization is checked server-side.')->required(),
            'name' => $schema->string()->min(1)->max(255)->description('Required product name.')->required(),
            'slug' => $schema->string()->nullable()->min(1)->max(255)->pattern('^[A-Za-z0-9_-]+$')->description('Optional Enterprise-unique slug. Generated from the name when omitted.'),
            'status' => $schema->string()->enum(array_column(ProductStatus::cases(), 'value'))->description('Lifecycle: draft, active, archived, or discontinued. Defaults to active.'),
            'product_type' => $schema->string()->enum(array_column(ProductType::cases(), 'value'))->description('Offering type: service, digital_product, subscription, course, editorial, physical_product, or other. Defaults to other.'),
            'tagline' => $schema->string()->nullable()->max(255)->description('Optional short tagline.'),
            'summary' => $schema->string()->nullable()->max(1000)->description('Optional short summary.'),
            'description' => $schema->string()->nullable()->max(50000)->description('Optional long-form description.'),
            'problem_statement' => $schema->string()->nullable()->max(10000)->description('Optional customer problem addressed.'),
            'value_proposition' => $schema->string()->nullable()->max(10000)->description('Optional value proposition.'),
            'primary_outcome' => $schema->string()->nullable()->max(5000)->description('Optional primary customer outcome.'),
            'positioning' => $schema->string()->nullable()->max(10000)->description('Optional product positioning.'),
            'delivery_model' => $schema->string()->enum(array_column(ProductDeliveryModel::cases(), 'value'))->description('Delivery model; defaults to other.'),
            'fulfillment_method' => $schema->string()->nullable()->max(255)->description('Optional fulfillment details distinct from delivery model.'),
            'support_model' => $schema->string()->nullable()->max(5000)->description('Optional support arrangement.'),
            'monetization_model' => $schema->string()->enum(array_column(ProductMonetizationModel::cases(), 'value'))->description('Revenue model; defaults to other.'),
            'pricing_model' => $schema->string()->enum(array_column(ProductPricingModel::cases(), 'value'))->description('Price calculation model; defaults to other.'),
            'price_amount' => $schema->number()->nullable()->min(0)->max(9999999999.99)->multipleOf(0.01)->description('Optional non-negative price with at most two decimal places.'),
            'currency' => $schema->string()->nullable()->min(3)->max(3)->pattern('^[A-Za-z]{3}$')->description('Optional ISO 4217 currency code; required when price_amount is provided.'),
            'billing_interval' => $schema->string()->nullable()->enum(array_column(ProductBillingInterval::cases(), 'value'))->description('Optional interval; recurring subscription configurations require weekly, monthly, quarterly, or yearly.'),
            'commercial_terms' => $schema->string()->nullable()->max(10000)->description('Optional commercial terms.'),
            'commercial_notes' => $schema->string()->nullable()->max(10000)->description('Optional internal commercial notes.'),
            'is_commercially_available' => $schema->boolean()->description('Whether the product is explicitly available commercially; independent of lifecycle status and defaults to false.'),
            'availability_start_at' => $schema->string()->nullable()->format('date-time')->description('Optional ISO 8601 start timestamp for commercial availability.'),
            'availability_end_at' => $schema->string()->nullable()->format('date-time')->description('Optional ISO 8601 end timestamp; must not precede availability_start_at.'),
        ];

        foreach (Product::STRUCTURED_LIST_FIELDS as $field) {
            $fields[$field] = $schema->array()->nullable()
                ->items($schema->string()->min(1)->max(500))
                ->max(50)
                ->description('Optional list of up to 50 '.Str::headline($field).' strings, each at most 500 characters.');
        }

        return $fields;
    }

    public function outputSchema(JsonSchema $schema): array
    {
        $productFields = static::schemaFields($schema);
        unset($productFields['enterprise_id']);
        $productFields = array_merge([
            'id' => $schema->integer()->required()->description('Persisted Product ID.'),
            'enterprise_id' => $schema->integer()->required()->description('Owning Enterprise ID.'),
        ], $productFields);
        $productFields['slug'] = $schema->string()->required()->description('Persisted Enterprise-unique slug.');

        foreach ($productFields as $field => $type) {
            $productFields[$field] = $type->required();
        }

        return [
            'success' => $schema->boolean()->required(),
            'result' => $schema->object($productFields)->required(),
        ];
    }

    protected static function rules(): array
    {
        return array_merge([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
        ], ProductInputRules::rules());
    }

    protected static function enterprise(array $validated): Enterprise
    {
        return Enterprise::query()->findOrFail((int) $validated['enterprise_id']);
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected static function targetContext(array $validated, ?Model $target = null): array
    {
        return ['enterprise_id' => (int) $validated['enterprise_id']];
    }

    /** @param array<string, mixed> $validated
     * @return array{0: string, 1: mixed}
     */
    protected static function humanAbility(array $validated, ?Model $target = null): array
    {
        return ['createForEnterprise', [Product::class, static::enterprise($validated)]];
    }

    protected static function mutate(User $actor, DomainResourceService $domain, array $validated, ?Model $target = null): Model
    {
        return $domain->createProduct($actor, static::enterprise($validated), $validated);
    }

    private static function isoDateTime(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? Carbon::instance($value)->toISOString() : null;
    }

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
            'is_commercially_available' => $record->is_commercially_available,
            'availability_start_at' => self::isoDateTime($record->getAttribute('availability_start_at')),
            'availability_end_at' => self::isoDateTime($record->getAttribute('availability_end_at')),
            'required_disclosures' => $record->required_disclosures,
            'restrictions' => $record->restrictions,
        ];
    }
}
