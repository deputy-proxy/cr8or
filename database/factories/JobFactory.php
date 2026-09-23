<?php

namespace Database\Factories;

use App\Models\Job;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Job> */
class JobFactory extends Factory
{
    protected $model = Job::class;

    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'name' => fake()->sentence(3),
            'idempotency_key' => (string) Str::uuid(),
            'attempts' => 0,
            'status' => Job::STATUS_PENDING,
            'started_at' => null,
            'completed_at' => null,
            'failure_reason' => null,
        ];
    }
}
