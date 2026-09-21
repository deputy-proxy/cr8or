<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\EnterpriseDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnterpriseDecision> */
class EnterpriseDecisionFactory extends Factory
{
    public function definition(): array
    {
        $actor = User::factory();

        return [
            'enterprise_id' => Enterprise::factory(),
            'actor_id' => $actor,
            'actor_name' => null,
            'title' => fake()->sentence(6),
            'summary' => fake()->paragraph(),
            'rationale' => fake()->optional()->paragraph(),
            'decided_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ];
    }

    public function by(User $actor): static
    {
        return $this->state([
            'actor_id' => $actor,
            'actor_name' => $actor->name,
        ]);
    }
}
