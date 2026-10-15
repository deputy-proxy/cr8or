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
            || $identity->provider !== 'instagram'
            || (int) $identity->organization_id !== (int) $account->enterprise->organization_id
            || (int) $connection->organization_id !== (int) $account->enterprise->organization_id
            || ($connection->enterprise_id !== null && (int) $connection->enterprise_id !== (int) $account->enterprise_id)
            || $connection->provider !== 'instagram'
            || ! $connection->isOperational()) {
            throw new SocialAuthorizationException('connection_not_operational', 'The Instagram destination has no matching operational Instagram Login connection.');
        }

        $instagramUserId = (string) $account->external_id;
        if ($instagramUserId === '' || ! ctype_digit($instagramUserId)) {
            throw new RuntimeException('The Instagram destination does not have a valid Instagram user ID.');
        }

        try {
            // Resolve through the standard credential lifecycle first, allowing the parent token to refresh.
            $this->credentials->resolveAccessToken($connection, 'instagram');
            $tokens = $this->credentialStore->forConnection($connection)->tokenSet();
        } catch (Throwable $exception) {
            throw new SocialAuthorizationException('credential_unavailable', 'The Instagram credential could not be resolved for follower synchronization.', previous: $exception);
        }

        $version = $this->graphApiVersion();

        if ($tokens->scopes !== [] && ! in_array('instagram_business_basic', $tokens->scopes, true)) {
            throw new SocialAuthorizationException('permission_denied', 'The Instagram authorization grant lacks instagram_business_basic. Reauthorize the connection with the required permission.');
        }

        if ($tokens->scopes === []) {
            throw new SocialAuthorizationException('permission_denied', 'The Instagram authorization scopes are missing from the stored grant. Reconnect the Instagram account.');
        }

        $destinationAccessToken = $tokens->accessToken;
        $expiresAt = $tokens->accessTokenExpiresAt?->toIso8601String();
        if (is_string($expiresAt) && $expiresAt !== '') {
            try {
                if (CarbonImmutable::parse($expiresAt)->lessThanOrEqualTo(now()->addMinute())) {
                    throw new SocialAuthorizationException('destination_token_expired', 'The stored Instagram User access token has expired. Reconnect the Instagram account.');
                }
            } catch (SocialAuthorizationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw new SocialAuthorizationException('destination_token_invalid', 'The stored Instagram User access token expiry is invalid.', previous: $exception);
            }
        } elseif ($expiresAt !== null) {
            throw new SocialAuthorizationException('destination_token_invalid', 'The stored Instagram User access token expiry is invalid.');
        }

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('social_authorization.timeout', 15))
                ->withToken($destinationAccessToken)
                ->get("https://graph.instagram.com/{$version}/me", [
                    'fields' => 'user_id,username,followers_count',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Instagram follower metrics request timed out or failed to connect.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Instagram rejected the follower metrics request.');
        }

        $body = $response->json();
        if (! is_array($body)
            || (string) ($body['user_id'] ?? '') !== $instagramUserId
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

    private function graphApiVersion(): string
    {
        $version = (string) config('social_authorization.providers.instagram.graph_api_version', 'v26.0');
        if (preg_match('/^v\d+\.\d+$/D', $version) !== 1) {
            throw new RuntimeException('The configured Meta Graph API version is invalid.');
        }

        return $version;
    }
}