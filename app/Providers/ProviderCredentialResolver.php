<?php

namespace App\Providers;

use App\Contracts\CredentialResolver;
use App\Models\IntegrationConnection;
use LogicException;

final class ProviderCredentialResolver implements CredentialResolver
{
    /**
     * @param  array<string, CredentialResolver>  $resolvers
     * @param  array<string, string>  $providerMap
     */
    public function __construct(
        private readonly array $resolvers,
        private readonly array $providerMap,
    ) {}

    public function resolveAccessToken(IntegrationConnection $connection, ?string $targetNetwork = null): string
    {
        $resolverKey = $this->providerMap[$connection->provider] ?? null;
        $resolver = is_string($resolverKey) ? ($this->resolvers[$resolverKey] ?? null) : null;

        if (! $resolver instanceof CredentialResolver) {
            throw new LogicException('No credential resolver is configured for this integration provider.');
        }

        return $resolver->resolveAccessToken($connection, $targetNetwork);
    }
}