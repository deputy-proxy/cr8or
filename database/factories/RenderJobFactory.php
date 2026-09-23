<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\Job;
use App\Models\RenderJob;
use App\Models\RenderRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RenderJob> */
class RenderJobFactory extends Factory
{
    protected $model = RenderJob::class;

    public function definition(): array
    {
        return ['render_request_id' => RenderRequest::factory(), 'workflow_job_id' => Job::factory(), 'execution_id' => Execution::factory(), 'external_job_id' => null, 'status' => 'pending', 'failure_reason' => null];
    }
}