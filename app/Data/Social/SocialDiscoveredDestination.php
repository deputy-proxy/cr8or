<?php

namespace App\Data\Social;

final readonly class SocialDiscoveredDestination
{
    public function __construct(
        public string $provider,
        public string $externalId,
        public string $name,
        public ?string $url = null,
        public ?string $credentialToken = null,
        public ?string $credentialExpiresAt = null,
    ) {}
}