<?php

namespace Database\Factories;

use App\Models\Enterprise;
use App\Models\Persona;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Persona> */
class PersonaFactory extends Factory
{
    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'name' => fake()->words(3, true), 'description' => fake()->optional()->paragraph(), 'status' => 'active'];
    }
}