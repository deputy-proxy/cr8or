<?php

namespace App\Domain\Products;

use App\Enums\ProductBillingInterval;
use App\Enums\ProductDeliveryModel;
use App\Enums\ProductMonetizationModel;
use App\Enums\ProductPricingModel;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Product;
use Closure;

final class ProductInputRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'min:1', 'max:255', 'alpha_dash'],
            'status' => ['sometimes', 'string', 'in:'.implode(',', array_column(ProductStatus::cases(), 'value'))],
            'product_type' => ['sometimes', 'string', 'in:'.implode(',', array_column(ProductType::cases(), 'value'))],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:255'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'problem_statement' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'value_proposition' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'primary_outcome' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'positioning' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'delivery_model' => ['sometimes', 'string', 'in:'.implode(',', array_column(ProductDeliveryModel::cases(), 'value'))],
            'fulfillment_method' => ['sometimes', 'nullable', 'string', 'max:255'],
            'support_model' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'monetization_model' => ['sometimes', 'string', 'in:'.implode(',', array_column(ProductMonetizationModel::cases(), 'value'))],
            'pricing_model' => ['sometimes', 'string', 'in:'.implode(',', array_column(ProductPricingModel::cases(), 'value'))],
            'price_amount' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'currency' => [
                'sometimes', 'nullable', 'string', 'size:3',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! Product::isValidCurrencyCode($value)) {
                        $fail('The currency must be a valid ISO 4217 currency code.');
                    }
                },
            ],
            'billing_interval' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_column(ProductBillingInterval::cases(), 'value'))],
            'commercial_terms' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'commercial_notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'is_commercially_available' => ['sometimes', 'boolean'],
            'availability_start_at' => ['sometimes', 'nullable', 'date'],
            'availability_end_at' => ['sometimes', 'nullable', 'date'],
        ];

        foreach (Product::STRUCTURED_LIST_FIELDS as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'array', 'list', 'max:50'];
            $rules[$field.'.*'] = ['required', 'string', 'min:1', 'max:500'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function normalize(array $attributes): array
    {
        foreach ([
            'name', 'tagline', 'summary', 'description', 'problem_statement', 'value_proposition',
            'primary_outcome', 'positioning', 'fulfillment_method', 'support_model', 'commercial_terms',
            'commercial_notes', 'slug',
        ] as $field) {
            if (! isset($attributes[$field]) || ! is_string($attributes[$field])) {
                continue;
            }

            $value = trim($attributes[$field]);
            $attributes[$field] = $value === '' && $field !== 'name' ? null : $value;
        }

        foreach (['currency', 'billing_interval', 'availability_start_at', 'availability_end_at', 'price_amount'] as $field) {
            if (array_key_exists($field, $attributes) && is_string($attributes[$field]) && trim($attributes[$field]) === '') {
                $attributes[$field] = null;
            }
        }

        if (isset($attributes['currency']) && is_string($attributes['currency'])) {
            $attributes['currency'] = strtoupper(trim($attributes['currency']));
        }

        foreach (Product::STRUCTURED_LIST_FIELDS as $field) {
            if (isset($attributes[$field]) && is_array($attributes[$field])) {
                $attributes[$field] = array_map(
                    static fn (mixed $item): mixed => is_string($item) ? trim($item) : $item,
                    $attributes[$field],
                );
            }
        }

        return $attributes;
    }
}
