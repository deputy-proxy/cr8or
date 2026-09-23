<?php

namespace App\Contracts;

use App\Models\GenerationRequest;

interface MediaGenerator
{
    /** @return array<string, mixed> */
    public function generate(GenerationRequest $request): array;
}
