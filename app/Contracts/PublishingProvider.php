<?php

namespace App\Contracts;

use App\Data\PublishingProviderResult;
use App\Data\PublishingRequest;

interface PublishingProvider
{
    public function publish(PublishingRequest $request): PublishingProviderResult;
}