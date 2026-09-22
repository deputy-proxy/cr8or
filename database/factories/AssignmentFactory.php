<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Enterprise;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assignment> */
class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    public function definition(): array
    {
        return ['enterprise_id' => Enterprise::factory(), 'assignable_type' => 'task', 'assignable_id' => 1, 'user_id' => null, 'agent_assignment_id' => null];
    }
}
