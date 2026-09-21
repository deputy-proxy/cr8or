<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\EnterpriseContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnterpriseContext> */
class EnterpriseContextFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'description' => fake()->optional()->paragraph(),
            'industry' => fake()->optional()->jobTitle(),
            'business_model' => fake()->optional()->randomElement([
                'subscription',
                'marketplace',
                'services',
                'product',
            ]),
            'target_market' => fake()->optional()->sentence(),
            'geography' => fake()->optional()->city(),
            'additional_context' => null,
        ];
    }
}
