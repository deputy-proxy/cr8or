<?php

namespace Database\Factories;

use App\Models\Execution;
use App\Models\GenerationJob;
use App\Models\GenerationRequest;
use App\Models\Job;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GenerationJob> */
class GenerationJobFactory extends Factory
{
    protected $model = GenerationJob::class;

    public function definition(): array
    {
        return ['generation_request_id' => GenerationRequest::factory(), 'workflow_job_id' => Job::factory(), 'execution_id' => Execution::factory(), 'external_job_id' => null, 'status' => 'pending', 'failure_reason' => null];
    }
}
