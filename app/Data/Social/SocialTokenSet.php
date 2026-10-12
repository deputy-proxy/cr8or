<?php

namespace App\Data\Social;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class SocialTokenSet
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string, array{access_token: string, expires_at: ?string}>  $destinationTokens
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?CarbonImmutable $accessTokenExpiresAt,
        public ?CarbonImmutable $refreshTokenExpiresAt,
        public array $scopes = [],
        public string $tokenType = 'Bearer',
        public array $destinationTokens = [],
    ) {
        if (trim($accessToken) === '') {
            throw new InvalidArgumentException('An access token is required.');
        }
    }

    /** @param array<string, array{access_token: string, expires_at: ?string}> $destinationTokens */
    public function withDestinationTokens(array $destinationTokens, bool $incomingWins = true): self
    {
        return new self(
            $this->accessToken,
            $this->refreshToken,
            $this->accessTokenExpiresAt,
            $this->refreshTokenExpiresAt,
            $this->scopes,
            $this->tokenType,
            $incomingWins ? array_merge($this->destinationTokens, $destinationTokens) : array_merge($destinationTokens, $this->destinationTokens),
        );
    }

    /** @return array<string, mixed> */
    public function encryptedPayload(): array
    {
        return [
            'access_token' => $this->accessToken,
            'refresh_token' => $this->refreshToken,
            'access_token_expires_at' => $this->accessTokenExpiresAt?->toIso8601String(),
            'refresh_token_expires_at' => $this->refreshTokenExpiresAt?->toIso8601String(),
            'scopes' => $this->scopes,
            'token_type' => $this->tokenType,
            'destination_tokens' => $this->destinationTokens,
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function fromEncryptedPayload(array $payload): self
    {
        $accessToken = $payload['access_token'] ?? null;
        $refreshToken = $payload['refresh_token'] ?? null;
        $accessExpiry = $payload['access_token_expires_at'] ?? null;
        $refreshExpiry = $payload['refresh_token_expires_at'] ?? null;
        $scopes = $payload['scopes'] ?? [];
        $destinationTokens = $payload['destination_tokens'] ?? [];

        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new InvalidArgumentException('Stored credential payload is invalid.');
        }

        $validatedDestinationTokens = [];
        if (is_array($destinationTokens)) {
            foreach ($destinationTokens as $network => $entry) {
                if (! is_string($network) || ! is_array($entry)) {
                    continue;
                }

                $destinationAccessToken = $entry['access_token'] ?? null;
                $destinationExpiresAt = $entry['expires_at'] ?? null;
                if (! is_string($destinationAccessToken) || $destinationAccessToken === '' || ($destinationExpiresAt !== null && ! is_string($destinationExpiresAt))) {
                    continue;
                }

                $validatedDestinationTokens[$network] = [
                    'access_token' => $destinationAccessToken,
                    'expires_at' => $destinationExpiresAt,
                ];
            }
        }

        return new self(
            $accessToken,
            is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            is_string($accessExpiry) ? CarbonImmutable::parse($accessExpiry) : null,
            is_string($refreshExpiry) ? CarbonImmutable::parse($refreshExpiry) : null,
            is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [],
            is_string($payload['token_type'] ?? null) ? $payload['token_type'] : 'Bearer',
            $validatedDestinationTokens,
        );
    }
}