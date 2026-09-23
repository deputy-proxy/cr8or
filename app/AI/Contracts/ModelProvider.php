<?php

namespace App\AI\Contracts;

use App\AI\Data\ModelRequest;
use App\AI\Data\ModelResult;

interface ModelProvider
{
    public function generate(ModelRequest $request): ModelResult;
}