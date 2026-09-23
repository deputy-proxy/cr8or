<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\PublicationResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicationResult> */
class PublicationResultFactory extends Factory
{
    public function definition(): array
    {
        $enterprise = Enterprise::factory();
        $publication = Publication::factory()->for($enterprise);

        return [
            'enterprise_id' => $enterprise,
            'publication_id' => $publication,
            'provider' => 'postiz',
            'provider_status' => 'submitted',
            'external_id' => fake()->uuid(),
            'correlation_id' => fake()->uuid(),
            'recorded_at' => now(),
        ];
    }
}