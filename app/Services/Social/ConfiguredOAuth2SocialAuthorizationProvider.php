<?php

namespace App\Services\Social;

use App\Contracts\SocialAuthorizationProvider;
use App\Data\Social\SocialDiscoveredDestination;
use App\Data\Social\SocialDiscoveryResult;
use App\Data\Social\SocialTokenSet;
use App\Enums\SocialRevocationResult;
use App\Exceptions\SocialAuthorizationException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

final class ConfiguredOAuth2SocialAuthorizationProvider implements SocialAuthorizationProvider
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        private readonly string $providerKey,
        private readonly array $configuration,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function authorizationUrl(string $state, string $redirectUri, array $scopes, ?string $codeChallenge): string
    {
        $url = $this->requiredUrl('authorization_url');
        $parameters = [
            $this->clientIdParameter(true) => $this->requiredString('client_id'),
            'redirect_uri' => $this->assertConfiguredRedirectUri($redirectUri),
            'response_type' => 'code',
            'scope' => implode((string) ($this->configuration['scope_separator'] ?? ' '), $scopes),
            'state' => $state,
        ];

        foreach (($this->configuration['authorization_parameters'] ?? []) as $key => $value) {
            $parameters[(string) $key] = $value;
        }

        if (($this->configuration['pkce'] ?? false) === true) {
            if ($codeChallenge === null || $codeChallenge === '') {
                throw new SocialAuthorizationException('pkce_required', 'This provider requires PKCE.');
            }
            $parameters['code_challenge'] = $codeChallenge;
            $parameters['code_challenge_method'] = 'S256';
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?string $codeVerifier): SocialTokenSet
    {
        if (trim($code) === '') {
            throw new SocialAuthorizationException('missing_authorization_code', 'The authorization provider did not return a code.');
        }

        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->assertConfiguredRedirectUri($redirectUri),
            $this->clientIdParameter() => $this->requiredString('client_id'),
        ];

        if (($this->configuration['pkce'] ?? false) === true) {
            if ($codeVerifier === null || $codeVerifier === '') {
                throw new SocialAuthorizationException('missing_code_verifier', 'The authorization attempt is missing its PKCE verifier.');
            }
            $parameters['code_verifier'] = $codeVerifier;
        }

        $response = $this->tokenRequest($this->requiredUrl('token_url'), $parameters);

        return $this->tokenSetFromResponse($response->json(), null);
    }

    public function refresh(SocialTokenSet $current): SocialTokenSet
    {
        $refresh = $this->configuration['refresh'] ?? [];
        $strategy = (string) ($refresh['strategy'] ?? 'none');

        if ($strategy === 'oauth2') {
            if ($current->refreshToken === null) {
                throw new SocialAuthorizationException('refresh_token_missing', 'The provider did not issue a refresh token.');
            }

            $parameters = [
                'grant_type' => 'refresh_token',
                'refresh_token' => $current->refreshToken,
                $this->clientIdParameter(false, $refresh) => $this->requiredString('client_id'),
            ];
            $response = $this->tokenRequest($this->requiredUrl('token_url'), $parameters, $refresh);

            return $this->tokenSetFromResponse($response->json(), $current);
        }

        if (in_array($strategy, ['meta_exchange', 'threads_long_lived'], true)) {
            $url = $this->requiredNestedString($refresh, 'url');
            $inputParameter = (string) ($refresh['input_parameter'] ?? 'access_token');
            $parameters = [
                'grant_type' => (string) ($refresh['grant_type'] ?? ''),
                $inputParameter => $current->accessToken,
            ];

            if ($strategy === 'meta_exchange') {
                $parameters['client_id'] = $this->requiredString('client_id');
                $parameters['client_secret'] = $this->requiredString('client_secret');
            }

            $response = $this->http()->withToken($current->accessToken)->get($url, $parameters);
            $this->assertSuccessful($response->successful(), $response->status(), 'token_refresh_failed');

            return $this->tokenSetFromResponse($response->json(), $current);
        }

        throw new SocialAuthorizationException('refresh_unsupported', 'This provider does not support the configured refresh flow.');
    }

    public function revoke(SocialTokenSet $current): SocialRevocationResult
    {
        $revoke = $this->configuration['revoke'] ?? [];
        $url = $revoke['url'] ?? null;
        if (! is_string($url) || $url === '') {
            return SocialRevocationResult::Unsupported;
        }

        $method = strtoupper((string) ($revoke['method'] ?? 'POST'));
        $parameter = (string) ($revoke['parameter'] ?? 'token');
        $parameters = [$parameter => $current->accessToken];
        $request = $this->http();

        if (($this->configuration['client_auth_method'] ?? 'body') === 'basic') {
            $request = $request->withBasicAuth($this->requiredString('client_id'), $this->requiredString('client_secret'));
        } else {
            $parameters['client_id'] = $this->requiredString('client_id');
        }

        $response = match ($method) {
            'GET' => $request->get($url, $parameters),
            'DELETE' => $request->delete($url, $parameters),
            default => $request->asForm()->post($url, $parameters),
        };

        return $response->successful() ? SocialRevocationResult::Revoked : SocialRevocationResult::Failed;
    }

    public function discover(SocialTokenSet $tokens, string $destinationNetwork): SocialDiscoveryResult
    {
        $allowedNetworks = $this->configuration['destination_networks'] ?? [];
        if (! in_array($destinationNetwork, $allowedNetworks, true)) {
            throw new SocialAuthorizationException('destination_network_not_supported', 'This authorization provider cannot discover the requested destination network.');
        }

        $identityConfig = $this->configuration['identity'] ?? [];
        $identityBody = $this->getJson($identityConfig, $tokens->accessToken);
        $identityId = data_get($identityBody, (string) ($identityConfig['id_path'] ?? 'id'));
        $identityName = data_get($identityBody, (string) ($identityConfig['name_path'] ?? 'name'));

        if (! is_string($identityId) || trim($identityId) === '') {
            throw new SocialAuthorizationException('identity_discovery_failed', 'The provider did not return a usable authenticated identity.');
        }

        $destinations = [];
        $responses = [];

        foreach (($this->configuration['destinations'] ?? []) as $destinationConfig) {
            if (($destinationConfig['network'] ?? null) !== $destinationNetwork) {
                continue;
            }

            $url = (string) ($destinationConfig['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $cacheKey = $url.'|'.json_encode($destinationConfig['query'] ?? []);
            if (! array_key_exists($cacheKey, $responses)) {
                $responses[$cacheKey] = $this->getJson($destinationConfig, $tokens->accessToken, $identityId);
            }

            $body = $responses[$cacheKey];
            $path = (string) ($destinationConfig['path'] ?? '');
            $items = $path === '' ? $body : data_get($body, $path);
            if (! is_array($items)) {
                continue;
            }
            if (! array_is_list($items)) {
                $items = [$items];
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $externalId = data_get($item, (string) ($destinationConfig['id_path'] ?? 'id'));
                if (! is_string($externalId) && ! is_int($externalId)) {
                    continue;
                }
                $externalId = trim((string) $externalId);
                $idPrefix = $destinationConfig['id_prefix'] ?? null;
                if (is_string($idPrefix) && $idPrefix !== '' && ! str_starts_with($externalId, $idPrefix)) {
                    $externalId = $idPrefix.$externalId;
                }
                if ($externalId === '') {
                    continue;
                }

                $name = data_get($item, (string) ($destinationConfig['name_path'] ?? 'name'));
                $urlValue = data_get($item, (string) ($destinationConfig['url_path'] ?? 'url'));

                if ((! is_string($name) || trim($name) === '' || trim($name) === $externalId)
                    && is_array($destinationConfig['metadata'] ?? null)
                    && preg_match('/^urn:li:organization:([0-9]+)$/D', $externalId, $organizationMatch) === 1) {
                    $metadataConfig = $destinationConfig['metadata'];
                    $metadataBody = $this->getJson($metadataConfig, $tokens->accessToken, $identityId, $organizationMatch[1], optionalNotFound: true);
                    $metadataNamePath = str_replace('{destination_id}', $organizationMatch[1], (string) ($metadataConfig['name_path'] ?? 'localizedName'));
                    $metadataName = data_get($metadataBody, $metadataNamePath);
                    if (is_string($metadataName) && trim($metadataName) !== '') {
                        $name = trim($metadataName);
                    }

                    $metadataUrlPath = str_replace('{destination_id}', $organizationMatch[1], (string) ($metadataConfig['url_path'] ?? ''));
                    $metadataUrl = $metadataUrlPath !== '' ? data_get($metadataBody, $metadataUrlPath) : null;
                    $urlPrefix = $metadataConfig['url_prefix'] ?? null;
                    if (is_string($metadataUrl) && $metadataUrl !== '' && is_string($urlPrefix) && $urlPrefix !== '') {
                        $urlValue = $urlPrefix.$metadataUrl;
                    }
                }

                $destinations[] = new SocialDiscoveredDestination(
                    $destinationNetwork,
                    $externalId,
                    is_string($name) && trim($name) !== '' ? trim($name) : $externalId,
                    is_string($urlValue) && str_starts_with($urlValue, 'https://') ? $urlValue : null,
                    is_string(data_get($item, (string) ($destinationConfig['credential_token_path'] ?? '__none__'))) ? data_get($item, (string) $destinationConfig['credential_token_path']) : null,
                    is_numeric(data_get($item, (string) ($destinationConfig['credential_expires_in_path'] ?? '__none__'))) ? CarbonImmutable::now()->addSeconds((int) data_get($item, (string) $destinationConfig['credential_expires_in_path']))->toIso8601String() : null,
                );
            }
        }

        $unique = [];
        foreach ($destinations as $destination) {
            $unique[$destination->provider.':'.$destination->externalId] = $destination;
        }

        return new SocialDiscoveryResult(
            $identityId,
            is_string($identityName) && trim($identityName) !== '' ? trim($identityName) : $identityId,
            array_values($unique),
        );
    }

    public function supportsRefresh(): bool
    {
        return in_array((string) data_get($this->configuration, 'refresh.strategy'), ['oauth2', 'meta_exchange', 'threads_long_lived'], true);
    }

    public function supportsRevocation(): bool
    {
        return is_string(data_get($this->configuration, 'revoke.url')) && data_get($this->configuration, 'revoke.url') !== '';
    }

    /** @param array<string, mixed> $parameters
     * @param  array<string, mixed>  $overrides
     */
    private function tokenRequest(string $url, array $parameters, array $overrides = []): \Illuminate\Http\Client\Response
    {
        if (($this->configuration['client_auth_method'] ?? 'body') === 'basic') {
            $request = $this->http()->withBasicAuth($this->requiredString('client_id'), $this->requiredString('client_secret'));
        } else {
            $parameters[$this->clientSecretParameter($overrides)] = $this->requiredString('client_secret');
            $request = $this->http();
        }

        $response = $request->asForm()->post($url, $parameters);
        if (! $response->successful()) {
            $providerError = data_get($response->json(), 'error');
            $knownErrors = ['invalid_grant', 'invalid_client', 'invalid_token', 'unauthorized_client', 'unsupported_grant_type'];
            $errorCode = is_string($providerError) && in_array($providerError, $knownErrors, true)
                ? $providerError
                : 'token_request_failed';

            throw new SocialAuthorizationException(
                $errorCode,
                'The social provider rejected the token request.',
                $response->status() === 429 || $response->status() >= 500,
            );
        }

        return $response;
    }

    /** @param array<string, mixed> $endpoint
     * @return array<string, mixed>
     */
    private function getJson(array $endpoint, string $accessToken, ?string $identityId = null, ?string $destinationId = null, bool $optionalNotFound = false): array
    {
        $url = $endpoint['url'] ?? null;
        if (! is_string($url) || $url === '') {
            throw new SocialAuthorizationException('provider_configuration_invalid', 'The provider discovery endpoint is not configured.');
        }

        $query = is_array($endpoint['query'] ?? null) ? $endpoint['query'] : [];
        foreach ($query as $key => $value) {
            if (is_string($value) && str_contains($value, '{identity_id}')) {
                if ($identityId === null || $identityId === '') {
                    throw new SocialAuthorizationException('provider_configuration_invalid', 'A discovery query requires the authenticated identity identifier.');
                }

                $value = str_replace('{identity_id}', $identityId, $value);
            }
            if (is_string($value) && str_contains($value, '{destination_id}')) {
                if ($destinationId === null || $destinationId === '') {
                    throw new SocialAuthorizationException('provider_configuration_invalid', 'A destination metadata query requires the destination identifier.');
                }

                $value = str_replace('{destination_id}', $destinationId, $value);
            }
            $query[$key] = $value;
        }

        $response = $this->http()->withToken($accessToken)
            ->withHeaders(is_array($endpoint['headers'] ?? null) ? $endpoint['headers'] : [])
            ->get($url, $query);

        if ($optionalNotFound && in_array($response->status(), [403, 404], true)) {
            return [];
        }

        $this->assertSuccessful($response->successful(), $response->status(), 'destination_discovery_failed');

        $body = $response->json();

        if (! is_array($body)) {
            throw new SocialAuthorizationException('provider_response_invalid', 'The provider returned an invalid discovery response.');
        }

        return $body;
    }

    private function tokenSetFromResponse(mixed $body, ?SocialTokenSet $previous): SocialTokenSet
    {
        if (! is_array($body)) {
            throw new SocialAuthorizationException('token_response_invalid', 'The provider returned an invalid token response.');
        }

        $paths = $this->configuration['token_response'] ?? [];
        $accessToken = data_get($body, (string) ($paths['access_token'] ?? 'access_token'));
        if (! is_string($accessToken) || trim($accessToken) === '') {
            throw new SocialAuthorizationException('token_response_invalid', 'The provider did not return an access token.');
        }

        $refreshToken = data_get($body, (string) ($paths['refresh_token'] ?? 'refresh_token'));
        if (! is_string($refreshToken) || $refreshToken === '') {
            $refreshToken = $previous?->refreshToken;
        }

        $expiresIn = data_get($body, (string) ($paths['expires_in'] ?? 'expires_in'));
        $refreshExpiresIn = data_get($body, (string) ($paths['refresh_expires_in'] ?? 'refresh_token_expires_in'));
        $scopeValue = data_get($body, (string) ($paths['scope'] ?? 'scope'));

        $scopes = [];
        if (is_array($scopeValue)) {
            $scopes = array_values(array_filter($scopeValue, 'is_string'));
        } elseif (is_string($scopeValue)) {
            $scopes = preg_split('/[\s,]+/', trim($scopeValue), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $scopes = $previous === null ? [] : $previous->scopes;
        }

        return new SocialTokenSet(
            $accessToken,
            $refreshToken,
            is_numeric($expiresIn) ? CarbonImmutable::now()->addSeconds(max(0, (int) $expiresIn)) : null,
            is_numeric($refreshExpiresIn) ? CarbonImmutable::now()->addSeconds(max(0, (int) $refreshExpiresIn)) : $previous?->refreshTokenExpiresAt,
            $scopes,
            is_string($body['token_type'] ?? null) ? $body['token_type'] : ($previous === null ? 'Bearer' : $previous->tokenType),
            $previous === null ? [] : $previous->destinationTokens,
        );
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()
            ->timeout((int) config('social_authorization.timeout', 15))
            ->withOptions(['allow_redirects' => false]);
    }

    private function assertConfiguredRedirectUri(string $redirectUri): string
    {
        $configured = $this->requiredString('redirect_uri');
        if (! hash_equals($configured, $redirectUri)) {
            throw new SocialAuthorizationException('redirect_uri_mismatch', 'The callback URI does not match provider configuration.');
        }

        $scheme = parse_url($configured, PHP_URL_SCHEME);
        if (app()->environment('production') && $scheme !== 'https') {
            throw new SocialAuthorizationException('redirect_uri_insecure', 'Production social authorization requires HTTPS callback URLs.');
        }

        return $configured;
    }

    private function requiredUrl(string $key): string
    {
        $url = $this->requiredString($key);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new SocialAuthorizationException('provider_configuration_invalid', 'Provider endpoints must use HTTPS.');
        }

        return $url;
    }

    private function requiredString(string $key): string
    {
        $value = $this->configuration[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new SocialAuthorizationException('provider_not_configured', 'The social authorization provider is not fully configured.');
        }

        return $value;
    }

    /** @param array<string, mixed> $array */
    private function requiredNestedString(array $array, string $key): string
    {
        $value = $array[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || parse_url($value, PHP_URL_SCHEME) !== 'https') {
            throw new SocialAuthorizationException('provider_configuration_invalid', 'The provider endpoint is not configured securely.');
        }

        return $value;
    }

    /** @param array<string, mixed> $overrides */
    private function clientIdParameter(bool $authorization = false, array $overrides = []): string
    {
        $key = $authorization ? 'authorization_client_id_parameter' : 'client_id_parameter';

        return (string) ($overrides[$key] ?? $this->configuration[$key] ?? 'client_id');
    }

    /** @param array<string, mixed> $overrides */
    private function clientSecretParameter(array $overrides = []): string
    {
        return (string) ($overrides['client_secret_parameter'] ?? $this->configuration['client_secret_parameter'] ?? 'client_secret');
    }

    private function assertSuccessful(bool $successful, int $status, string $errorCode): void
    {
        if ($successful) {
            return;
        }

        throw new SocialAuthorizationException(
            $errorCode,
            'The social provider request failed.',
            $status === 429 || $status >= 500,
        );
    }
}