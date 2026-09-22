<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\KnowledgeSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<KnowledgeSource> */
class KnowledgeSourceFactory extends Factory
{
    protected $model = KnowledgeSource::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'name' => fake()->sentence(3), 'type' => 'document', 'description' => fake()->optional()->paragraph(), 'uri' => fake()->optional()->url(), 'metadata' => null];
    }
}
