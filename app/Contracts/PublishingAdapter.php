<?php

namespace App\Contracts;

use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingCapabilities;

interface PublishingAdapter extends IntegrationProvider
{
    public function capabilities(): PublishingCapabilities;

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult;
}
