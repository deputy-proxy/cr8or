<?php

namespace App\Providers;

use App\Contracts\CredentialResolver;
use App\Exceptions\SocialAuthorizationException;
use App\Models\IntegrationConnection;
use App\Services\Social\SocialAuthorizationProviderRegistry;
use App\Services\Social\SocialCredentialManager;

final class SocialCredentialResolver implements CredentialResolver
{
    public function __construct(
        private readonly SocialAuthorizationProviderRegistry $providers,
        private readonly SocialCredentialManager $credentials,
    ) {}

    public function resolveAccessToken(IntegrationConnection $connection, ?string $targetNetwork = null): string
    {
        $this->providers->forProvider($connection->provider);

        if ($targetNetwork === null || ! in_array($targetNetwork, $this->providers->destinationNetworks($connection->provider), true)) {
            throw new SocialAuthorizationException('target_network_not_authorized', 'A social credential must be resolved for a destination network authorized by this connection.');
        }

        return $this->credentials->resolveAccessToken($connection);
    }
}