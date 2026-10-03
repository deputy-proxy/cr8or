<?php

namespace Database\Factories;

use App\Models\GenerationJob;
use App\Models\GenerationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GenerationJob> */
class GenerationJobFactory extends Factory
{
    protected $model = GenerationJob::class;

    public function definition(): array
    {
        return [
            'generation_request_id' => GenerationRequest::factory(),
            'external_job_id' => null,
            'status' => GenerationJob::STATUS_PENDING,
            'failure_reason' => null,
        ];
    }
}