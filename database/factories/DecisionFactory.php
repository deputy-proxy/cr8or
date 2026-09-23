<?php

namespace Database\Factories;

use App\Models\Decision;
use App\Models\Enterprise;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Decision> */
class DecisionFactory extends Factory
{
    public function definition(): array
    {
        $actor = User::factory();

        return [
            'enterprise_id' => Enterprise::factory(),
            'type' => 'operational',
            'actor_id' => $actor,
            'actor_name' => null,
            'objective_id' => null,
            'strategy_id' => null,
            'plan_id' => null,
            'initiative_id' => null,
            'project_id' => null,
            'task_id' => null,
            'work_item_id' => null,
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

    public function strategic(): static
    {
        return $this->state(['type' => 'strategic']);
    }
}
