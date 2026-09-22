<?php

namespace Database\Factories;

use App\Models\Dependency;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dependency> */
class DependencyFactory extends Factory
{
    protected $model = Dependency::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'project_id' => null, 'predecessor_type' => 'task', 'predecessor_id' => 1, 'successor_type' => 'task', 'successor_id' => 2, 'type' => 'blocks'];
    }
}
