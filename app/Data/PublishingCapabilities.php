<?php

namespace App\Data;

/**
 * @param  list<string>  $networks
 * @param  list<string>  $supportedMediaTypes
 */
final readonly class PublishingCapabilities
{
    /**
     * @param  list<string>  $networks
     * @param  list<string>  $supportedMediaTypes
     */
    public function __construct(
        public array $networks,
        public array $supportedMediaTypes = [],
        public ?int $maxTextLength = null,
        public bool $supportsScheduling = false,
        public bool $supportsIdempotency = false,
    ) {}

    public function supportsNetwork(string $network): bool
    {
        return in_array($network, $this->networks, true);
    }

    public function supportsMediaType(string $mimeType): bool
    {
        return in_array($mimeType, $this->supportedMediaTypes, true);
    }
}