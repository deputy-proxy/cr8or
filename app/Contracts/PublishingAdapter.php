<?php

namespace App\Contracts;

use App\Data\PublishingAdapterRequest;
use App\Data\PublishingAdapterResult;
use App\Data\PublishingCapabilities;

interface PublishingAdapter extends IntegrationProvider
{
    /** The single canonical destination network this adapter implements. */
    public function network(): string;

    public function capabilities(): PublishingCapabilities;

    public function publish(PublishingAdapterRequest $request): PublishingAdapterResult;
}