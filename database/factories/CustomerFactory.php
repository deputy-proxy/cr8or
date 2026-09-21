<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enterprise_id' => Enterprise::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'status' => 'active',
        ];
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => 'inactive',
        ]);
    }
}