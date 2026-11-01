<?php

namespace App\Providers;

use App\Contracts\CredentialResolver;
use App\Models\IntegrationConnection;
use App\Services\GitHubAppTokenService;
use RuntimeException;

final class GitHubCredentialResolver implements CredentialResolver
{
    public function __construct(private readonly GitHubAppTokenService $appTokens) {}

    public function resolveAccessToken(IntegrationConnection $connection, ?string $targetNetwork = null): string
    {
        if ($targetNetwork !== null || $connection->provider !== 'github' || ! $connection->isOperational()) {
            throw new RuntimeException('An active GitHub integration connection is required.');
        }

        if ($connection->github_installation_id !== null) {
            return $this->appTokens->installationToken($connection);
        }

        // Transitional support for existing installations until the legacy repository migration is complete.
        $credentials = config('services.github.credentials', []);
        $reference = $connection->credentialReference()->value;
        $token = is_array($credentials) ? ($credentials[$reference] ?? null) : null;
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('GitHub App installation is not linked and no legacy credential is configured.');
        }

        return $token;
    }
}
