<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\FinancialPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FinancialPeriod> */
class FinancialPeriodFactory extends Factory
{
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('-1 year', '+1 year');
        $end = (clone $start)->modify('+1 month');

        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => fake()->unique()->bothify('Period ####'),
            'period_start' => $start->format('Y-m-d'),
            'period_end' => $end->format('Y-m-d'),
            'status' => FinancialPeriod::STATUS_ACTIVE,
        ];
    }

    public function closed(): static
    {
        return $this->state([
            'status' => FinancialPeriod::STATUS_CLOSED,
        ]);
    }
}
