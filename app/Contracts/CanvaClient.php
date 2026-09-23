<?php

namespace App\Contracts;

use App\Data\CanvaDesignRequest;
use App\Data\CanvaDesignResult;
use App\Models\IntegrationConnection;

interface CanvaClient
{
    public function createDesign(IntegrationConnection $connection, CanvaDesignRequest $request): CanvaDesignResult;
}