<?php

namespace Database\Factories;

use App\Models\RenderJob;
use App\Models\RenderRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RenderJob> */
class RenderJobFactory extends Factory
{
    protected $model = RenderJob::class;

    public function definition(): array
    {
        return [
            'render_request_id' => RenderRequest::factory(),
            'external_job_id' => null,
            'status' => RenderJob::STATUS_PENDING,
            'failure_reason' => null,
        ];
    }
}