<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Enterprise> */
class EnterpriseFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 999999),
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state([
            'status' => 'archived',
        ]);
    }
}