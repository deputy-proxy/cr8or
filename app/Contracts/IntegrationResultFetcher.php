<?php

namespace App\Contracts;

use App\Data\Integrations\IntegrationResultEnvelope;
use App\Models\IntegrationJob;

interface IntegrationResultFetcher
{
    public function fetch(IntegrationJob $job): ?IntegrationResultEnvelope;
}