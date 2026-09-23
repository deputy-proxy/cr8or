<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\GenerationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GenerationRequest> */
class GenerationRequestFactory extends Factory
{
    protected $model = GenerationRequest::class;

    public function definition(): array
    {
        $a = Asset::factory();

        return ['enterprise_id' => null, 'content_item_id' => null, 'asset_id' => $a, 'type' => 'image', 'parameters' => ['prompt' => 'test'], 'status' => GenerationRequest::STATUS_PENDING, 'idempotency_key' => (string) Str::uuid(), 'correlation_id' => (string) Str::uuid(), 'external_request_id' => null, 'failure_code' => null, 'failure_reason' => null];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (GenerationRequest $r): void {
            $r->enterprise_id = $r->asset->enterprise_id;
        });
    }
}