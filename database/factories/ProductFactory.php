<?php

namespace Database\Factories;

use App\Enums\ProductDeliveryModel;
use App\Enums\ProductMonetizationModel;
use App\Enums\ProductPricingModel;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Enterprise;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->sentence(2);

        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'status' => ProductStatus::ACTIVE->value,
            'product_type' => ProductType::OTHER->value,
            'delivery_model' => ProductDeliveryModel::OTHER->value,
            'monetization_model' => ProductMonetizationModel::OTHER->value,
            'pricing_model' => ProductPricingModel::OTHER->value,
            'is_commercially_available' => false,
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => ProductStatus::ARCHIVED->value]);
    }
}
