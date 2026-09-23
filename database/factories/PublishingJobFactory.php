<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\PublishingJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublishingJob> */
class PublishingJobFactory extends Factory
{
    public function definition(): array
    {
        $enterprise = Enterprise::factory();
        $publication = Publication::factory()->for($enterprise);

        return [
            'enterprise_id' => $enterprise,
            'publication_id' => $publication,
            'idempotency_key' => fake()->unique()->uuid(),
            'status' => PublishingJob::STATUS_PENDING,
        ];
    }
}