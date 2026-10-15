<?php

namespace App\Services\Social;

use App\Contracts\CredentialResolver;
use App\Exceptions\SocialAuthorizationException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class InstagramFollowerCountSynchronizer
{
    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly SocialCredentialStore $credentialStore,
    ) {}

    public function synchronize(SocialAccount $account): int
    {
        $account->loadMissing(['enterprise', 'channel', 'socialIdentity.integrationConnection']);

        if ($account->enterprise === null || $account->channel === null) {
            throw new RuntimeException('The Instagram destination is missing its enterprise or channel association.');
        }

        if ($account->provider !== 'instagram' || $account->status !== SocialAccount::STATUS_ACTIVE) {
            throw new RuntimeException('Only active Instagram professional account destinations can have follower counts synchronized.');
        }

        $identity = $account->socialIdentity;
        $connection = $identity?->integrationConnection;

        if ($identity === null
            || $connection === null
            || ! in_array($identity->provider, ['meta', 'instagram'], true)
            || (int) $identity->organization_id !== (int) $account->enterprise->organization_id
            || (int) $connection->organization_id !== (int) $account->enterprise->organization_id
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $account->enterprise_id)
            || ! in_array($connection->provider, ['meta', 'instagram'], true)
            || $identity->provider !== $connection->provider
            || ! $connection->isOperational()) {
            throw new SocialAuthorizationException('connection_not_operational', 'The Instagram destination has no matching operational Instagram Login connection.');
        }

        $instagramUserId = (string) $account->external_id;
        if ($instagramUserId === '' || ! ctype_digit($instagramUserId)) {
            throw new RuntimeException('The Instagram destination does not have a valid Instagram user ID.');
        }

        try {
            // Resolve through the standard credential lifecycle first, allowing the parent token to refresh.
            $userToken = $this->credentials->resolveAccessToken($connection, 'instagram');
            $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        } catch (Throwable $exception) {
            throw new SocialAuthorizationException('credential_unavailable', 'The Instagram credential could not be resolved for follower synchronization.', previous: $exception);
        }

        $version = $this->graphApiVersion($connection->provider);
        $requiredScope = $connection->provider === 'meta' ? 'instagram_basic' : 'instagram_business_basic';

        if ($tokens->scopes !== [] && ! in_array($requiredScope, $tokens->scopes, true)) {
            throw new SocialAuthorizationException('permission_denied', "The Instagram authorization grant lacks {$requiredScope}. Reauthorize the connection with the required permission.");
        }
        if ($tokens->scopes === [] && $connection->provider === 'meta') {
            $this->assertInstagramBasicPermission($userToken, $version);
        } elseif ($tokens->scopes === []) {
            throw new SocialAuthorizationException('permission_denied', 'The Instagram Login grant has no verifiable scope metadata; reconnect the account.');
        }

        $destinationToken = $tokens->destinationTokens['instagram:'.$instagramUserId] ?? null;
        $destinationAccessToken = is_array($destinationToken) ? $destinationToken['access_token'] : null;

        if (! is_string($destinationAccessToken) || trim($destinationAccessToken) === '') {
            throw new SocialAuthorizationException('destination_token_missing', 'No destination token is stored for this Instagram professional account.');
        }

        $expiresAt = $destinationToken['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                if (CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(now()->addMinute())) {
                    throw new SocialAuthorizationException('destination_token_expired', 'The stored Instagram destination token has expired. Reconnect Meta and select the account again.');
                }
            } catch (SocialAuthorizationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new SocialAuthorizationException('destination_token_invalid', 'The stored Instagram destination token expiry is invalid.', previous: $exception);
            }
        } elseif ($expiresAt !== null) {
            throw new SocialAuthorizationException('destination_token_invalid', 'The stored Instagram destination token expiry is invalid.');
        }

        try {
            $response = Http::withoutRedirecting()->acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($destinationAccessToken)
                ->get(($connection->provider === 'meta' ? 'https://graph.facebook.com/' : 'https://graph.instagram.com/').$version.'/'.rawurlencode($instagramUserId), [
                    'fields' => $connection->provider === 'meta' ? 'id,username,followers_count' : 'user_id,username,followers_count',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Instagram follower metrics request timed out or failed to connect.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Instagram rejected the follower metrics request.');
        }

        $body = $response->json();
        if (! is_array($body)
            || (string) ($body['user_id'] ?? $body['id'] ?? '') !== $instagramUserId
            || ! is_int($body['followers_count'] ?? null)
            || $body['followers_count'] < 0) {
            throw new RuntimeException('Instagram returned an invalid follower metrics response.');
        }

        $account->follower_count = $body['followers_count'];

        // Meta's username is used to construct the canonical Instagram profile URL. Preserve an existing URL
        // when the API omits it or returns an invalid username.
        $username = $body['username'] ?? null;
        if (is_string($username) && preg_match('/^[A-Za-z0-9._]{1,30}$/D', $username) === 1) {
            $account->profile_url = 'https://www.instagram.com/'.$username.'/';
        }

        $account->save();

        return $body['followers_count'];
    }

    private function assertInstagramBasicPermission(string $userToken, string $version): void
    {
        try {
            $response = Http::withoutRedirecting()->acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($userToken)
                ->get('https://graph.facebook.com/'.$version.'/me/permissions');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Legacy Instagram permission status could not be verified before follower synchronization.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new SocialAuthorizationException('permission_status_unavailable', 'Legacy Instagram permission status could not be verified before follower synchronization.');
        }

        $permissions = $response->json('data');
        if (! is_array($permissions)) {
            throw new SocialAuthorizationException('permission_status_invalid', 'Meta did not return permission status for legacy Instagram follower synchronization.');
        }

        foreach ($permissions as $permission) {
            if (is_array($permission)
                && ($permission['permission'] ?? null) === 'instagram_basic'
                && ($permission['status'] ?? null) === 'granted') {
                return;
            }
        }

        throw new SocialAuthorizationException('permission_denied', 'The legacy Meta grant lacks instagram_basic; reconnect through Instagram Login.');
    }

    private function graphApiVersion(string $provider): string
    {
        $version = (string) config("social_authorization.providers.{$provider}.graph_api_version", 'v26.0');
        if (preg_match('/^v\d+\.\d+$/D', $version) !== 1) {
            throw new RuntimeException('The configured Instagram Graph API version is invalid.');
        }

        return $version;
    }
}