<?php

namespace App\Data\Social;

use InvalidArgumentException;

/** @param array<string, mixed> $context */
final readonly class SocialAuthorizationInitiation
{
    /** @param array<string, mixed> $context */
    public function __construct(public string $url, public array $context)
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('Social authorization initiation URLs must use HTTPS.');
        }
    }
}
