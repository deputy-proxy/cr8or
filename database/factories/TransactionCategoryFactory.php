<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\TransactionCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransactionCategory> */
class TransactionCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
