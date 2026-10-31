<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Initiative;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Initiative> */
class InitiativeFactory extends Factory
{
    protected $model = Initiative::class;

    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'strategy_id' => null,
            'name' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Initiative $initiative): void {
            if ($initiative->strategy_id !== null) {
                $initiative->enterprise_id = Strategy::query()->findOrFail($initiative->strategy_id)->objective->enterprise_id;
            }
        });
    }

    public function forStrategy(Strategy $strategy): static
    {
        return $this->state([
            'enterprise_id' => $strategy->objective->enterprise_id,
            'strategy_id' => $strategy->id,
        ]);
    }
}
