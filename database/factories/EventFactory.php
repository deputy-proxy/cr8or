<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Event;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Event> */
class EventFactory extends Factory
{
    public function definition(): array
    {
        $organization = Organization::factory();
        $enterprise = Enterprise::factory()->for($organization);

        return [
            'organization_id' => $organization,
            'enterprise_id' => $enterprise,
            'source' => Event::SOURCE_CR8OR,
            'event_type' => 'enterprise_updated',
            'description' => 'Enterprise updated',
            'occurred_at' => now(),
            'payload' => [],
            'source_event_id' => (string) Str::uuid(),
            'actor_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'correlation_id' => null,
            'causation_id' => null,
            'received_at' => now(),
        ];
    }
}
