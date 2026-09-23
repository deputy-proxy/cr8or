<?php

namespace App\Data;

/** @param array<string, mixed> $payload */
final readonly class PublishingProviderResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(public string $externalId, public ?string $externalUrl, public string $status, public array $payload = []) {}
}