<?php

namespace App\Services\Social;

use App\Contracts\SocialAuthorizationProvider;
use App\Data\Social\SocialDiscoveredDestination;
use App\Data\Social\SocialDiscoveryResult;
use App\Data\Social\SocialTokenSet;
use App\Enums\SocialRevocationResult;
use App\Exceptions\SocialAuthorizationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Native Instagram Login; intentionally separate from Facebook Login and Page discovery. */
final class InstagramLoginAuthorizationProvider implements SocialAuthorizationProvider
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private readonly array $configuration) {}

    public function key(): string
    {
        return 'instagram';
    }

    public function authorizationUrl(string $state, string $redirectUri, array $scopes, ?string $codeChallenge): string
    {
        $parameters = [
            'client_id' => $this->requiredString('client_id'),
            'redirect_uri' => $this->assertRedirectUri($redirectUri),
            'response_type' => 'code',
            'scope' => implode(',', $scopes),
            'state' => $state,
            'enable_fb_login' => '0',
        ];

        return $this->requiredUrl('authorization_url').'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?string $codeVerifier): SocialTokenSet
    {
        if (trim($code) === '') {
            throw new SocialAuthorizationException('missing_authorization_code', 'Instagram did not return an authorization code.');
        }

        try {
            $response = $this->http()->asMultipart()->post($this->requiredUrl('token_url'), [
                'client_id' => $this->requiredString('client_id'),
                'client_secret' => $this->requiredString('client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->assertRedirectUri($redirectUri),
                'code' => $code,
            ]);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('token_exchange_failed', 'Instagram token exchange could not be completed.', true, previous: $exception);
        }

        $body = $response->json();
        $shortLivedToken = is_array($body) ? ($body['access_token'] ?? null) : null;
        $grantedScopes = is_array($body) ? ($body['permissions'] ?? $body['scope'] ?? null) : null;
        if (is_string($grantedScopes)) {
            $grantedScopes = preg_split('/[\\s,]+/', trim($grantedScopes), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $grantedScopes = is_array($grantedScopes)
            ? array_values(array_unique(array_filter($grantedScopes, 'is_string')))
            : [];
        $requiredScopes = array_values(array_filter($this->configuration['scopes'] ?? [], 'is_string'));
        if (! $response->successful() || ! is_string($shortLivedToken) || trim($shortLivedToken) === '') {
            throw new SocialAuthorizationException('token_exchange_failed', 'Instagram rejected the authorization-code exchange.', $response->status() === 429 || $response->serverError());
        }
        if ($requiredScopes === [] || array_diff($requiredScopes, $grantedScopes) !== []) {
            throw new SocialAuthorizationException('permission_denied', 'Instagram did not grant every required permission. Review the Instagram Login app configuration and reconnect with the required scopes.');
        }

        try {
            $longLivedResponse = $this->http()->get('https://graph.instagram.com/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->requiredString('client_secret'),
                'access_token' => $shortLivedToken,
            ]);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('long_lived_token_exchange_failed', 'Instagram could not issue a long-lived access token.', true, previous: $exception);
        }

        $longLivedBody = $longLivedResponse->json();
        $accessToken = is_array($longLivedBody) ? ($longLivedBody['access_token'] ?? null) : null;
        $expiresIn = is_array($longLivedBody) ? ($longLivedBody['expires_in'] ?? null) : null;
        if (! $longLivedResponse->successful() || ! is_string($accessToken) || trim($accessToken) === '' || ! is_numeric($expiresIn) || (int) $expiresIn < 1) {
            throw new SocialAuthorizationException('long_lived_token_exchange_failed', 'Instagram did not return a valid long-lived access token.', $longLivedResponse->status() === 429 || $longLivedResponse->serverError());
        }

        // Persist only the permissions returned by the short-lived token exchange; never infer
        // that requested permissions were granted merely because the user completed OAuth.
        return new SocialTokenSet(
            accessToken: $accessToken,
            refreshToken: null,
            accessTokenExpiresAt: CarbonImmutable::now()->addSeconds((int) $expiresIn),
            refreshTokenExpiresAt: null,
            scopes: $grantedScopes,
        );
    }

    public function refresh(SocialTokenSet $current): SocialTokenSet
    {
        if ($current->accessTokenExpiresAt === null || $current->accessTokenExpiresAt->isPast()) {
            throw new SocialAuthorizationException('invalid_token', 'The Instagram access token has expired; reconnect the account.', false);
        }

        $refresh = $this->configuration['refresh'] ?? [];
        $url = is_array($refresh) ? ($refresh['url'] ?? null) : null;
        if (! is_string($url) || trim($url) === '') {
            throw new SocialAuthorizationException('refresh_unsupported', 'Instagram token refresh is not configured.');
        }

        try {
            $response = $this->http()->get($url, [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $current->accessToken,
            ]);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('token_refresh_failed', 'Instagram token refresh could not be completed.', true, previous: $exception);
        }

        $body = $response->json();
        $accessToken = is_array($body) ? ($body['access_token'] ?? null) : null;
        $expiresIn = is_array($body) ? ($body['expires_in'] ?? null) : null;
        if (! $response->successful() || ! is_string($accessToken) || trim($accessToken) === '' || ! is_numeric($expiresIn) || (int) $expiresIn < 1) {
            throw new SocialAuthorizationException('invalid_token', 'Instagram rejected token refresh; reconnect the account.', false);
        }

        return new SocialTokenSet(
            accessToken: $accessToken,
            refreshToken: null,
            accessTokenExpiresAt: CarbonImmutable::now()->addSeconds((int) $expiresIn),
            refreshTokenExpiresAt: null,
            scopes: $current->scopes,
            tokenType: $current->tokenType,
        );
    }

    public function revoke(SocialTokenSet $current): SocialRevocationResult
    {
        // Instagram Login revocation/deauthorization is not treated as successful unless a supported
        // provider endpoint is explicitly configured. Local disconnect remains available.
        return SocialRevocationResult::Unsupported;
    }

    public function discover(SocialTokenSet $tokens, string $destinationNetwork): SocialDiscoveryResult
    {
        if ($destinationNetwork !== 'instagram') {
            throw new SocialAuthorizationException('destination_network_not_supported', 'Instagram Login can only discover Instagram destinations.');
        }

        $url = $this->identityUrl();
        try {
            $response = $this->http()->withToken($tokens->accessToken)->get($url);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('identity_discovery_failed', 'Instagram account discovery could not be completed.', true, previous: $exception);
        }

        $body = $response->json();
        $userId = is_array($body) ? ($body['user_id'] ?? $body['id'] ?? null) : null;
        $username = is_array($body) ? ($body['username'] ?? null) : null;
        $accountType = is_array($body) ? ($body['account_type'] ?? null) : null;
        if (! $response->successful()
            || (! is_string($userId) && ! is_int($userId))
            || preg_match('/^[0-9]+$/D', (string) $userId) !== 1
            || ! is_string($username)
            || preg_match('/^[A-Za-z0-9._]{1,30}$/D', $username) !== 1
            || ! is_string($accountType)
            || ! in_array(strtoupper($accountType), ['BUSINESS', 'CREATOR', 'MEDIA_CREATOR'], true)) {
            throw new SocialAuthorizationException('identity_discovery_failed', 'Instagram did not return an eligible professional account profile.');
        }

        $userId = (string) $userId;
        $destination = new SocialDiscoveredDestination(
            provider: 'instagram',
            externalId: $userId,
            name: $username,
            url: 'https://www.instagram.com/'.$username.'/',
        );

        return new SocialDiscoveryResult($userId, $username, [$destination]);
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function supportsRevocation(): bool
    {
        return false;
    }

    private function identityUrl(): string
    {
        $url = $this->configuration['identity']['url'] ?? null;
        if (! is_string($url) || trim($url) === '' || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new SocialAuthorizationException('provider_configuration_invalid', 'Instagram profile discovery is not configured securely.');
        }

        return $url;
    }

    private function assertRedirectUri(string $redirectUri): string
    {
        $configured = $this->requiredString('redirect_uri');
        if (! hash_equals($configured, $redirectUri)) {
            throw new SocialAuthorizationException('redirect_uri_mismatch', 'The Instagram callback URI does not match provider configuration.');
        }
        if (app()->environment('production') && parse_url($configured, PHP_URL_SCHEME) !== 'https') {
            throw new SocialAuthorizationException('redirect_uri_insecure', 'Production Instagram authorization requires HTTPS callback URLs.');
        }

        return $configured;
    }

    private function requiredString(string $key): string
    {
        $value = $this->configuration[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new SocialAuthorizationException('provider_not_configured', 'Instagram Login is not fully configured.');
        }

        return $value;
    }

    private function requiredUrl(string $key): string
    {
        $url = $this->requiredString($key);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new SocialAuthorizationException('provider_configuration_invalid', 'Instagram OAuth endpoints must use HTTPS.');
        }

        return $url;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('social_authorization.timeout', 15))
            ->withOptions(['allow_redirects' => false]);
    }
}
