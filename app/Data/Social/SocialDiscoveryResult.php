<?php

namespace App\Data\Social;

/**
 * @param  list<SocialDiscoveredDestination>  $destinations
 */
final readonly class SocialDiscoveryResult
{
    /** @param list<SocialDiscoveredDestination> $destinations */
    public function __construct(
        public string $identityExternalId,
        public string $identityName,
        public array $destinations,
    ) {}
}