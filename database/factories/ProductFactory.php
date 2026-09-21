<?php

namespace Database\Factories;

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
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state([
            'status' => 'archived',
        ]);
    }
}