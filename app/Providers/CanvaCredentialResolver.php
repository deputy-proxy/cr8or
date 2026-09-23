<?php

namespace App\Providers;

use App\Contracts\CredentialResolver;
use App\Exceptions\CanvaClientException;
use App\Models\IntegrationConnection;

final class CanvaCredentialResolver implements CredentialResolver
{
    public function resolveAccessToken(IntegrationConnection $connection): string
    {
        if ($connection->provider !== 'canva') {
            throw new CanvaClientException('Unsupported credential provider.', 'invalid_connection');
        }

        $reference = trim($connection->credential_reference);
        $token = (string) config("services.canva.credentials.{$reference}", '');

        if ($token === '') {
            throw new CanvaClientException('Canva credentials are not configured for this connection.', 'credentials_unavailable', true);
        }

        return $token;
    }
}