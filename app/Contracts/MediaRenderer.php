<?php

namespace App\Contracts;

use App\Models\RenderRequest;

interface MediaRenderer
{
    /** @return array<string, mixed> */
    public function render(RenderRequest $request): array;
}