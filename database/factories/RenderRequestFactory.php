<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\RenderRequest;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RenderRequest> */
class RenderRequestFactory extends Factory
{
    protected $model = RenderRequest::class;

    public function definition(): array
    {
        $a = Asset::factory();

        return ['enterprise_id' => null, 'content_item_id' => null, 'asset_id' => $a, 'source_version_id' => null, 'type' => 'video', 'parameters' => [], 'status' => RenderRequest::STATUS_PENDING, 'idempotency_key' => (string) Str::uuid(), 'correlation_id' => (string) Str::uuid(), 'external_request_id' => null, 'failure_code' => null, 'failure_reason' => null];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (RenderRequest $r): void {
            $r->enterprise_id = $r->asset->enterprise_id;
        });
    }
}
