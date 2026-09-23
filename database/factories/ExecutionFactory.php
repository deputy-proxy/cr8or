<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Execution> */
class ExecutionFactory extends Factory
{
    protected $model = Execution::class;

    public function definition(): array
    {
        return [
            'workflow_job_id' => Job::factory(),
            'organization_id' => null,
            'enterprise_id' => null,
            'project_id' => null,
            'task_id' => null,
            'work_item_id' => null,
            'organization_name' => null,
            'enterprise_name' => null,
            'project_name' => null,
            'task_name' => null,
            'work_item_name' => null,
            'status' => Execution::STATUS_PENDING,
            'started_at' => null,
            'completed_at' => null,
            'failure_reason' => null,
        ];
    }
}
