<?php

namespace App\Services\Social;

use App\Contracts\StatefulSocialAuthorizationProvider;
use App\Data\Social\SocialAuthorizationInitiation;
use App\Data\Social\SocialDiscoveredDestination;
use App\Data\Social\SocialDiscoveryResult;
use App\Data\Social\SocialTokenSet;
use App\Enums\SocialRevocationResult;
use App\Exceptions\SocialAuthorizationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class InstagramLoginAuthorizationProvider implements StatefulSocialAuthorizationProvider
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private readonly array $configuration) {}

    public function key(): string
    {
        return 'instagram';
    }

    public function authorizationUrl(string $state, string $redirectUri, array $scopes, ?string $codeChallenge): string
    {
        return $this->initiate($state, $redirectUri, $scopes, '', null)->url;
    }

    public function initiate(string $state, string $redirectUri, array $scopes, string $codeVerifier, ?string $accountIdentifier): SocialAuthorizationInitiation
    {
        $this->assertRedirectUri($redirectUri);
        $url = 'https://www.instagram.com/oauth/authorize?'.http_build_query([
            'client_id' => $this->requiredString('client_id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(',', $scopes),
            'state' => $state,
            'enable_fb_login' => '0',
        ], '', '&', PHP_QUERY_RFC3986);

        return new SocialAuthorizationInitiation($url, ['scopes' => $scopes]);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?string $codeVerifier): SocialTokenSet
    {
        throw new SocialAuthorizationException('authorization_context_missing', 'Instagram authorization context is required.');
    }

    public function exchangeWithContext(string $code, string $redirectUri, string $codeVerifier, array $context, array $callbackParameters): SocialTokenSet
    {
        $this->assertRedirectUri($redirectUri);
        if (trim($code) === '') {
            throw new SocialAuthorizationException('missing_authorization_code', 'Instagram did not return an authorization code.');
        }

        $scopes = $context['scopes'] ?? null;
        if (! is_array($scopes) || $scopes === [] || array_filter($scopes, 'is_string') !== $scopes) {
            throw new SocialAuthorizationException('authorization_context_invalid', 'The requested Instagram permissions could not be verified.');
        }

        try {
            $short = Http::withoutRedirecting()->asForm()->acceptJson()->timeout($this->timeout())->post('https://api.instagram.com/oauth/access_token', [
                'client_id' => $this->requiredString('client_id'),
                'client_secret' => $this->requiredString('client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ]);
            if (! $short->successful() || ! is_array($short->json()) || ! is_string($short->json('access_token')) || ! is_numeric($short->json('user_id'))) {
                throw new SocialAuthorizationException('token_exchange_failed', 'Instagram rejected the authorization-code exchange.');
            }

            $long = Http::withoutRedirecting()->acceptJson()->timeout($this->timeout())->get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->requiredString('client_secret'),
                'access_token' => $short->json('access_token'),
            ]);
            if (! $long->successful() || ! is_array($long->json()) || ! is_string($long->json('access_token')) || ! is_numeric($long->json('expires_in')) || (int) $long->json('expires_in') < 1) {
                throw new SocialAuthorizationException('token_exchange_failed', 'Instagram did not issue a valid long-lived access token.');
            }

            $accessToken = $long->json('access_token');
            $profile = Http::withoutRedirecting()->acceptJson()->timeout($this->timeout())->withToken($accessToken)->get($this->graphUrl('/me'), [
                'fields' => 'user_id,username,account_type',
            ]);
            $body = $profile->json();
            if (! $profile->successful() || ! is_array($body)
                || (string) ($body['user_id'] ?? $body['id'] ?? '') !== (string) $short->json('user_id')
                || ! is_string($body['username'] ?? null)
                || ! in_array($body['account_type'] ?? null, ['BUSINESS', 'MEDIA_CREATOR'], true)) {
                throw new SocialAuthorizationException('identity_discovery_failed', 'Instagram did not return the authorized professional account identity.');
            }

            $expiresAt = CarbonImmutable::now()->addSeconds((int) $long->json('expires_in'));
            $destinationToken = ['instagram:'.(string) $short->json('user_id') => [
                'access_token' => $accessToken,
                'expires_at' => $expiresAt->toIso8601String(),
            ]];

            return new SocialTokenSet($accessToken, null, $expiresAt, null, array_values($scopes), 'Bearer', $destinationToken, [
                'user_id' => (string) $short->json('user_id'),
                'username' => $body['username'],
                'account_type' => $body['account_type'],
            ]);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('provider_unavailable', 'Instagram authorization could not be completed because the provider is unavailable.', true, $exception);
        }
    }

    public function refresh(SocialTokenSet $current): SocialTokenSet
    {
        if (! in_array('instagram_business_basic', $current->scopes, true)) {
            throw new SocialAuthorizationException('invalid_token', 'Instagram token refresh requires instagram_business_basic; reconnect the account.');
        }

        try {
            $response = Http::withoutRedirecting()->acceptJson()->timeout($this->timeout())->get('https://graph.instagram.com/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $current->accessToken,
            ]);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('provider_unavailable', 'Instagram token refresh could not be completed because the provider is unavailable.', true, $exception);
        }

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ! is_string($body['access_token'] ?? null) || ! is_numeric($body['expires_in'] ?? null) || (int) $body['expires_in'] < 1) {
            throw new SocialAuthorizationException('invalid_token', 'Instagram rejected the long-lived token refresh; reconnect the account.');
        }

        $expiresAt = CarbonImmutable::now()->addSeconds((int) $body['expires_in']);
        $destinationTokens = [];
        foreach ($current->destinationTokens as $key => $token) {
            if (str_starts_with($key, 'instagram:')) {
                $destinationTokens[$key] = ['access_token' => $body['access_token'], 'expires_at' => $expiresAt->toIso8601String()];
            }
        }

        return new SocialTokenSet($body['access_token'], null, $expiresAt, null, $current->scopes, 'Bearer', $destinationTokens, $current->providerContext);
    }

    public function revoke(SocialTokenSet $current): SocialRevocationResult
    {
        return SocialRevocationResult::Unsupported;
    }

    public function discover(SocialTokenSet $tokens, string $destinationNetwork): SocialDiscoveryResult
    {
        if ($destinationNetwork !== 'instagram') {
            throw new SocialAuthorizationException('destination_network_unsupported', 'Instagram Login only supports Instagram destinations.');
        }

        try {
            $response = Http::withoutRedirecting()->acceptJson()->timeout($this->timeout())->withToken($tokens->accessToken)->get($this->graphUrl('/me'), [
                'fields' => 'user_id,username,account_type',
            ]);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('provider_unavailable', 'Instagram account discovery failed because the provider is unavailable.', true, $exception);
        }

        $body = $response->json();
        $id = is_array($body) ? (string) ($body['user_id'] ?? $body['id'] ?? '') : '';
        $username = is_array($body) ? ($body['username'] ?? null) : null;
        if (! $response->successful() || $id === '' || ! ctype_digit($id)
            || ! is_string($username) || preg_match('/^[A-Za-z0-9._]{1,30}$/D', $username) !== 1
            || ! in_array($body['account_type'] ?? null, ['BUSINESS', 'MEDIA_CREATOR'], true)) {
            throw new SocialAuthorizationException('identity_discovery_failed', 'Instagram did not return a valid professional account.');
        }

        $expiresAt = $tokens->accessTokenExpiresAt?->toIso8601String();

        return new SocialDiscoveryResult($id, '@'.$username, [
            new SocialDiscoveredDestination('instagram', $id, '@'.$username, 'https://www.instagram.com/'.$username.'/', $tokens->accessToken, $expiresAt),
        ]);
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function supportsRevocation(): bool
    {
        return false;
    }

    private function graphUrl(string $path): string
    {
        $version = trim((string) ($this->configuration['graph_api_version'] ?? 'v26.0'), '/');
        if (preg_match('/^v[0-9]+\.[0-9]+$/D', $version) !== 1) {
            throw new SocialAuthorizationException('provider_configuration_invalid', 'The configured Instagram Graph API version is invalid.');
        }

        return 'https://graph.instagram.com/'.$version.$path;
    }

    private function timeout(): int
    {
        return max(1, (int) config('social_authorization.timeout', 15));
    }

    private function requiredString(string $key): string
    {
        $value = $this->configuration[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new SocialAuthorizationException('provider_not_configured', 'Instagram Login is not fully configured.');
        }

        return $value;
    }

    private function assertRedirectUri(string $redirectUri): void
    {
        $configured = $this->configuration['redirect_uri'] ?? null;
        if (! is_string($configured) || ! hash_equals($configured, $redirectUri)) {
            throw new SocialAuthorizationException('redirect_uri_mismatch', 'The Instagram callback URI does not match provider configuration.');
        }
        $parts = parse_url($redirectUri);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? null) : null;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $isLocalHttp = $scheme === 'http' && is_string($host) && in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if (filter_var($redirectUri, FILTER_VALIDATE_URL) === false
            || isset($parts['user']) || isset($parts['pass'])
            || ($scheme !== 'https' && ! $isLocalHttp)) {
            throw new SocialAuthorizationException('provider_configuration_invalid', 'Instagram callback URI must use HTTPS outside local development.');
        }
    }
}