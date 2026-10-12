<?php

namespace App\Services\Social;

use App\Contracts\SocialAuthorizationProvider;
use App\Exceptions\SocialAuthorizationException;

final class SocialAuthorizationProviderRegistry
{
    public function providerForNetwork(string $network): string
    {
        $provider = config("social_authorization.network_to_provider.{$network}");
        if (! is_string($provider) || $provider === '') {
            throw new SocialAuthorizationException('destination_network_unsupported', 'This destination network is not configured for social authorization.');
        }

        return $provider;
    }

    /** @return list<string> */
    public function destinationNetworks(string $provider): array
    {
        $networks = config("social_authorization.providers.{$provider}.destination_networks", []);

        return is_array($networks) ? array_values(array_filter($networks, 'is_string')) : [];
    }

    /** @return array<string, mixed> */
    public function configuration(string $provider): array
    {
        $configuration = config("social_authorization.providers.{$provider}");
        if (! is_array($configuration)) {
            throw new SocialAuthorizationException('provider_unsupported', 'This social authorization provider is not configured.');
        }

        return $configuration;
    }

    public function forProvider(string $provider): SocialAuthorizationProvider
    {
        $configuration = $this->configuration($provider);
        $driver = $configuration['driver'] ?? null;

        if ($driver === 'unsupported') {
            $reason = $configuration['reason'] ?? 'A dedicated authorization protocol driver is required.';
            throw new SocialAuthorizationException('provider_protocol_unsupported', is_string($reason) ? $reason : 'A dedicated authorization protocol driver is required.');
        }

        if ($driver !== 'oauth2') {
            throw new SocialAuthorizationException('provider_unsupported', 'This social authorization provider has no supported driver.');
        }

        if (($configuration['enabled'] ?? false) !== true) {
            throw new SocialAuthorizationException('provider_not_configured', 'The social authorization provider is not configured for this deployment.');
        }

        return new ConfiguredOAuth2SocialAuthorizationProvider($provider, $configuration);
    }
}