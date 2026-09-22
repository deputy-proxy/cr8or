<?php

namespace Database\Factories;

use App\Experts\Expert;
use App\Models\ExpertDescriptor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExpertDescriptor> */
class ExpertDescriptorFactory extends Factory
{
    public function definition(): array
    {
        $words = fake()->unique()->words(2);

        return [
            'slug' => Str::slug(is_array($words) ? implode(' ', $words) : $words),
            'runtime_class' => Expert::class,
            'enabled' => true,
        ];
    }

    /**
     * @param  class-string<Expert>  $runtimeClass
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
