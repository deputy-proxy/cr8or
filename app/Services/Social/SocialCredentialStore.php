<?php

namespace App\Services\Social;

use App\Data\Social\SocialTokenSet;
use App\Exceptions\SocialAuthorizationException;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\SocialCredential;
use Illuminate\Support\Str;

final class SocialCredentialStore
{
    public function store(Organization $organization, string $provider, SocialTokenSet $tokens): string
    {
        $reference = 'social:'.Str::uuid();

        $credential = new SocialCredential;
        $credential->forceFill([
            'reference' => $reference,
            'organization_id' => $organization->id,
            'provider' => $provider,
            'encrypted_payload' => $tokens->encryptedPayload(),
            'scopes' => $tokens->scopes,
            'access_token_expires_at' => $tokens->accessTokenExpiresAt,
            'refresh_token_expires_at' => $tokens->refreshTokenExpiresAt,
        ]);
        $credential->save();

        return $reference;
    }

    public function forConnection(IntegrationConnection $connection): SocialCredential
    {
        $reference = $connection->credential_reference;
        if (! str_starts_with($reference, 'social:')) {
            throw new SocialAuthorizationException('credential_reference_invalid', 'The social connection does not reference a managed social credential.');
        }

        $credential = SocialCredential::query()
            ->where('reference', $reference)
            ->where('organization_id', $connection->organization_id)
            ->where('provider', $connection->provider)
            ->first();

        if ($credential === null) {
            throw new SocialAuthorizationException('credential_not_found', 'The social connection credential could not be resolved.');
        }

        return $credential;
    }

    public function update(SocialCredential $credential, SocialTokenSet $tokens): void
    {
        $credential->forceFill([
            'encrypted_payload' => $tokens->encryptedPayload(),
            'scopes' => $tokens->scopes,
            'access_token_expires_at' => $tokens->accessTokenExpiresAt,
            'refresh_token_expires_at' => $tokens->refreshTokenExpiresAt,
        ])->save();
    }

    public function deleteReference(string $reference, int $organizationId, string $provider): void
    {
        SocialCredential::query()
            ->where('reference', $reference)
            ->where('organization_id', $organizationId)
            ->where('provider', $provider)
            ->delete();
    }
}