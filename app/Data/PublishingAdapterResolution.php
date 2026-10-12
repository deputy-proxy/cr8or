<?php

namespace App\Data;

use App\Contracts\PublishingAdapter;

final readonly class PublishingAdapterResolution
{
    public function __construct(
        public string $network,
        public string $adapterKey,
        public PublishingAdapter $adapter,
        public PublishingCapabilities $capabilities,
        public bool $useNativeScheduling,
    ) {}
}