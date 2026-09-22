<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeContext> */
class KnowledgeContextFactory extends Factory
{
    protected $model = KnowledgeContext::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'name' => fake()->sentence(3), 'type' => 'general', 'description' => fake()->optional()->paragraph(), 'data' => null];
    }
}