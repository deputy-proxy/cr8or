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
            || $identity->provider !== 'meta'
            || (int) $identity->organization_id !== (int) $account->enterprise->organization_id
            || (int) $connection->organization_id !== (int) $account->enterprise->organization_id
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $account->enterprise_id)
            || $connection->provider !== 'meta'
            || ! $connection->isOperational()) {
            throw new SocialAuthorizationException('connection_not_operational', 'The Instagram destination has no matching operational Meta connection.');
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
            throw new SocialAuthorizationException('credential_unavailable', 'The Meta credential could not be resolved for follower synchronization.', previous: $exception);
        }

        $version = $this->graphApiVersion();

        if ($tokens->scopes !== [] && ! in_array('instagram_basic', $tokens->scopes, true)) {
            throw new SocialAuthorizationException('permission_denied', 'The Meta authorization grant lacks instagram_basic. Reauthorize the connection with the required permission.');
        }

        if ($tokens->scopes === []) {
            $this->assertInstagramBasicPermission($userToken, $version);
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
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($destinationAccessToken)
                ->get("https://graph.facebook.com/{$version}/".rawurlencode($instagramUserId), [
                    'fields' => 'id,username,followers_count',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Meta follower metrics request timed out or failed to connect.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Meta rejected the Instagram follower metrics request.');
        }

        $body = $response->json();
        if (! is_array($body)
            || (string) ($body['id'] ?? '') !== $instagramUserId
            || ! is_int($body['followers_count'] ?? null)
            || $body['followers_count'] < 0) {
            throw new RuntimeException('Meta returned an invalid Instagram follower metrics response.');
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
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($userToken)
                ->get('https://graph.facebook.com/'.$version.'/me/permissions');
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Meta permission status could not be verified before follower synchronization.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new SocialAuthorizationException('permission_status_unavailable', 'Meta permission status could not be verified before follower synchronization.');
        }

        $permissions = $response->json('data');
        if (! is_array($permissions)) {
            throw new SocialAuthorizationException('permission_status_invalid', 'Meta did not return permission status for follower synchronization.');
        }

        foreach ($permissions as $permission) {
            if (is_array($permission)
                && ($permission['permission'] ?? null) === 'instagram_basic'
                && ($permission['status'] ?? null) === 'granted') {
                return;
            }
        }

        throw new SocialAuthorizationException('permission_denied', 'Meta has not granted instagram_basic for this connection.');
    }

    private function graphApiVersion(): string
    {
        $version = (string) config('social_authorization.providers.meta.graph_api_version', 'v26.0');
        if (preg_match('/^v\d+\.\d+$/D', $version) !== 1) {
            throw new RuntimeException('The configured Meta Graph API version is invalid.');
        }

        return $version;
    }
}