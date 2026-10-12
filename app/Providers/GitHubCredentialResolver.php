<?php

namespace App\Providers;

use App\Contracts\CredentialResolver;
use App\Models\IntegrationConnection;
use RuntimeException;

final class GitHubCredentialResolver implements CredentialResolver
{
    public function resolveAccessToken(IntegrationConnection $connection, ?string $targetNetwork = null): string
    {
        if ($targetNetwork !== null || $connection->provider !== 'github' || ! $connection->isOperational()) {
            throw new RuntimeException('An active GitHub integration connection is required.');
        }

        $credentials = config('services.github.credentials', []);
        $reference = $connection->credentialReference()->value;
        $token = is_array($credentials) ? ($credentials[$reference] ?? null) : null;

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('GitHub credentials are not configured for the selected credential reference.');
        }

        return $token;
    }
}