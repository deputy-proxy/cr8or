<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketingStrategy> */
class MarketingStrategyFactory extends Factory
{
    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'name' => fake()->sentence(3), 'description' => fake()->optional()->paragraph(), 'status' => 'draft'];
    }
}
