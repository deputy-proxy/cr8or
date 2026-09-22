<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Goal;
use App\Models\Kpi;
use App\Models\Objective;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Objective> */
class ObjectiveFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'goal_id' => null,
            'kpi_id' => null,
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
        ];
    }

    public function forGoal(Goal $goal): static
    {
        return $this->state([
            'enterprise_id' => $goal->enterprise_id,
            'goal_id' => $goal->getKey(),
        ]);
    }

    public function forKpi(Kpi $kpi): static
    {
        return $this->state([
            'enterprise_id' => $kpi->enterprise_id,
            'kpi_id' => $kpi->getKey(),
        ]);
    }
}