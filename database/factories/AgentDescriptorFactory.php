<?php

namespace Database\Factories;

use App\Agents\Agent;
use App\Models\AgentDescriptor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AgentDescriptor> */
class AgentDescriptorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug' => Str::slug(fake()->unique()->words(2, true)),
            'runtime_class' => Agent::class,
            'enabled' => true,
        ];
    }

    /**
     * @param  class-string<Agent>  $runtimeClass
     */
    public function forRuntimeClass(string $runtimeClass): static
    {
        return $this->state([
            'runtime_class' => $runtimeClass,
        ]);
    }

    public function disabled(): static
    {
        return $this->state([
            'enabled' => false,
        ]);
    }
}
