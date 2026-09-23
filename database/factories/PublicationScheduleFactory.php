<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Publication;
use App\Models\PublicationSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicationSchedule> */
class PublicationScheduleFactory extends Factory
{
    public function definition(): array
    {
        $enterprise = Enterprise::factory();
        $publication = Publication::factory()->for($enterprise);

        return [
            'enterprise_id' => $enterprise,
            'publication_id' => $publication,
            'scheduled_at' => now()->addHour(),
            'status' => PublicationSchedule::STATUS_SCHEDULED,
        ];
    }
}
