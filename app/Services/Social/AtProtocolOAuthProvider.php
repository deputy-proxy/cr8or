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
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class AtProtocolOAuthProvider implements StatefulSocialAuthorizationProvider
{
    /** @param array<string, mixed> $configuration */
    public function __construct(private readonly array $configuration) {}

    public function key(): string
    {
        return 'bluesky';
    }

    public function supportsRefresh(): bool
    {
        return true;
    }

    public function supportsRevocation(): bool
    {
        return false;
    }

    public function revoke(SocialTokenSet $current): SocialRevocationResult
    {
        return SocialRevocationResult::Unsupported;
    }

    public function authorizationUrl(string $state, string $redirectUri, array $scopes, ?string $codeChallenge): string
    {
        throw new SocialAuthorizationException('stateful_flow_required', 'AT Protocol authorization requires its session-bound OAuth flow.');
    }

    public function initiate(string $state, string $redirectUri, array $scopes, string $codeVerifier, ?string $accountIdentifier): SocialAuthorizationInitiation
    {
        if (! is_string($accountIdentifier) || trim($accountIdentifier) === '') {
            throw new SocialAuthorizationException('account_identifier_required', 'Enter a Bluesky handle or DID before connecting the account.');
        }
        $clientId = $this->requiredHttpsUrl('client_id');
        $this->assertClientMetadataUrl($clientId);
        $this->assertClientUri($clientId, $this->requiredHttpsUrl('client_uri'));
        $redirectUri = $this->assertHttpsUrl($redirectUri, 'redirect URI');
        if ($redirectUri !== $this->requiredHttpsUrl('redirect_uri')) {
            throw new SocialAuthorizationException('redirect_uri_mismatch', 'The configured Bluesky callback URI does not match the authorization request.');
        }
        if (! in_array('atproto', $scopes, true) || ! in_array('repo:app.bsky.feed.post?action=create', $scopes, true) || ! in_array('blob:*/*', $scopes, true)) {
            throw new SocialAuthorizationException('scope_not_allowed', 'Bluesky publishing requires identity, create-only feed-post and blob-upload permissions.');
        }
        $identity = $this->resolveIdentity(trim($accountIdentifier));
        $resource = $this->fetchJson($identity['pds_origin'].'/.well-known/oauth-protected-resource');
        if (($resource['resource'] ?? null) !== $identity['pds_origin']) {
            throw new SocialAuthorizationException('resource_metadata_invalid', 'The protected-resource metadata does not identify the resolved account PDS.');
        }
        $servers = $resource['authorization_servers'] ?? null;
        if (! is_array($servers) || $servers === [] || ! is_string($servers[0])) {
            throw new SocialAuthorizationException('resource_metadata_invalid', 'The account PDS did not advertise one valid authorization server.');
        }
        $issuer = $this->assertOrigin($servers[0], 'authorization server');
        $metadata = $this->fetchJson($issuer.'/.well-known/oauth-authorization-server');
        if (($metadata['issuer'] ?? null) !== $issuer
            || ($metadata['client_id_metadata_document_supported'] ?? false) !== true
            || ($metadata['require_pushed_authorization_requests'] ?? false) !== true
            || ($metadata['authorization_response_iss_parameter_supported'] ?? false) !== true
            || ! in_array('code', $metadata['response_types_supported'] ?? [], true)
            || ! in_array('authorization_code', $metadata['grant_types_supported'] ?? [], true)
            || ! in_array('refresh_token', $metadata['grant_types_supported'] ?? [], true)
            || ! in_array('S256', $metadata['code_challenge_methods_supported'] ?? [], true)
            || ! in_array('none', $metadata['token_endpoint_auth_methods_supported'] ?? [], true)
            || ! in_array('private_key_jwt', $metadata['token_endpoint_auth_methods_supported'] ?? [], true)
            || ! in_array('ES256', $metadata['token_endpoint_auth_signing_alg_values_supported'] ?? [], true)
            || (($metadata['require_request_uri_registration'] ?? true) === false)
            || ! in_array('atproto', $metadata['scopes_supported'] ?? [], true)
            || ! in_array('ES256', $metadata['dpop_signing_alg_values_supported'] ?? [], true)) {
            throw new SocialAuthorizationException('authorization_server_unsupported', 'The account authorization server does not meet the AT Protocol OAuth security profile.');
        }
        $authorizeEndpoint = $this->metadataEndpoint($metadata, 'authorization_endpoint');
        $tokenEndpoint = $this->metadataEndpoint($metadata, 'token_endpoint');
        $parEndpoint = $this->metadataEndpoint($metadata, 'pushed_authorization_request_endpoint');
        $keyPair = app(AtProtoDpopProof::class)->generateKeyPair();
        $context = [
            'expected_did' => $identity['did'], 'expected_handle' => $identity['handle'], 'pds_origin' => $identity['pds_origin'],
            'issuer' => $issuer, 'token_endpoint' => $tokenEndpoint, 'private_key_pem' => $keyPair['private_key_pem'],
            'jwk' => $keyPair['jwk'], 'dpop_thumbprint' => $keyPair['thumbprint'], 'dpop_nonces' => [],
        ];
        $parameters = [
            'client_id' => $clientId, 'redirect_uri' => $redirectUri, 'response_type' => 'code', 'scope' => implode(' ', $scopes),
            'state' => $state, 'code_challenge' => $this->base64UrlEncode(hash('sha256', $codeVerifier, true)),
            'code_challenge_method' => 'S256', 'dpop_jkt' => $keyPair['thumbprint'], 'login_hint' => trim($accountIdentifier),
        ];
        $nonce = null;
        $response = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $proof = app(AtProtoDpopProof::class)->create($context['private_key_pem'], $context['jwk'], 'POST', $parEndpoint, $nonce);
            try {
                $response = Http::asForm()->acceptJson()->timeout((int) config('social_authorization.timeout', 15))
                    ->withOptions(['allow_redirects' => false])->withHeaders(['DPoP' => $proof])->post($parEndpoint, $parameters);
            } catch (ConnectionException $exception) {
                throw new SocialAuthorizationException('authorization_request_failed', 'The Bluesky authorization server could not be reached.', true, $exception);
            }
            $challengeNonce = $response->header('DPoP-Nonce');
            if ($attempt === 0 && in_array($response->status(), [400, 401], true) && trim($challengeNonce) !== '') {
                $nonce = $challengeNonce;

                continue;
            }
            break;
        }
        if (! $response->successful()) {
            throw new SocialAuthorizationException('authorization_request_rejected', 'The Bluesky authorization server rejected the request. Verify the public client metadata URL and requested scopes.');
        }
        $requestUri = $response->json('request_uri');
        $responseNonce = $response->header('DPoP-Nonce');
        $nonce = trim($responseNonce) !== '' ? $responseNonce : $nonce;
        if (! is_string($requestUri) || trim($requestUri) === '' || trim($nonce) === '') {
            throw new SocialAuthorizationException('authorization_response_invalid', 'The authorization server omitted its request URI or required DPoP nonce.');
        }
        $context = $this->withNonce($context, $issuer, $nonce);
        $url = $authorizeEndpoint.'?'.http_build_query(['client_id' => $clientId, 'request_uri' => $requestUri], '', '&', PHP_QUERY_RFC3986);

        return new SocialAuthorizationInitiation($url, $context);
    }

    public function exchangeAuthorizationCode(string $code, string $redirectUri, ?string $codeVerifier): SocialTokenSet
    {
        throw new SocialAuthorizationException('stateful_flow_required', 'AT Protocol token exchange requires its stored DPoP session context.');
    }

    /** @param array<string, mixed> $context @param array<string, mixed> $callbackParameters */
    public function exchangeWithContext(string $code, string $redirectUri, string $codeVerifier, array $context, array $callbackParameters): SocialTokenSet
    {
        $issuer = $context['issuer'] ?? null;
        $callbackIssuer = $callbackParameters['iss'] ?? null;
        if (! is_string($issuer) || ! is_string($callbackIssuer) || ! hash_equals($issuer, $callbackIssuer)) {
            throw new SocialAuthorizationException('authorization_issuer_mismatch', 'The callback issuer does not match the account authorization server.');
        }
        $endpoint = $context['token_endpoint'] ?? null;
        if (! is_string($endpoint) || ! is_string($context['private_key_pem'] ?? null) || ! $this->hasValidJwk($context['jwk'] ?? null)) {
            throw new SocialAuthorizationException('authorization_context_invalid', 'The stored Bluesky OAuth context is incomplete.');
        }
        $response = $this->tokenRequest($endpoint, ['grant_type' => 'authorization_code', 'client_id' => $this->requiredHttpsUrl('client_id'), 'redirect_uri' => $this->assertHttpsUrl($redirectUri, 'redirect URI'), 'code' => $code, 'code_verifier' => $codeVerifier], $context);
        $body = $this->successfulTokenBody($response);
        if (! is_string($body['sub'] ?? null) || ! is_string($context['expected_did'] ?? null) || ! hash_equals($context['expected_did'], $body['sub'])) {
            throw new SocialAuthorizationException('account_identity_mismatch', 'The authorization server authenticated a different DID than the one resolved before authorization.');
        }
        $scopes = $this->parseScopes($body['scope'] ?? null);
        if (! in_array('atproto', $scopes, true) || ! in_array('repo:app.bsky.feed.post?action=create', $scopes, true) || ! in_array('blob:*/*', $scopes, true)) {
            throw new SocialAuthorizationException('scope_not_granted', 'The account did not grant the required post-creation and blob-upload permissions.');
        }
        $access = $body['access_token'] ?? null;
        $refresh = $body['refresh_token'] ?? null;
        $expires = $body['expires_in'] ?? null;
        $nonce = $response->header('DPoP-Nonce');
        if (! is_string($access) || trim($access) === '' || ! is_string($refresh) || trim($refresh) === '' || ! is_numeric($expires) || (int) $expires < 1 || trim($nonce) === '') {
            throw new SocialAuthorizationException('token_response_invalid', 'The authorization server returned an incomplete token response or omitted the required DPoP nonce.');
        }
        $context = $this->withNonce($context, $issuer, $nonce);

        return new SocialTokenSet($access, $refresh, CarbonImmutable::now()->addSeconds((int) $expires), null, $scopes, 'DPoP', [], $context);
    }

    public function refresh(SocialTokenSet $current): SocialTokenSet
    {
        if (! is_string($current->refreshToken) || $current->refreshToken === '') {
            throw new SocialAuthorizationException('refresh_token_missing', 'The Bluesky session has no refresh token; reconnect the account.');
        }
        $context = $current->providerContext;
        $endpoint = $context['token_endpoint'] ?? null;
        if (! is_string($endpoint)) {
            throw new SocialAuthorizationException('authorization_context_invalid', 'The stored Bluesky OAuth context is incomplete.');
        }
        $response = $this->tokenRequest($endpoint, ['grant_type' => 'refresh_token', 'client_id' => $this->requiredHttpsUrl('client_id'), 'refresh_token' => $current->refreshToken], $context);
        $body = $this->successfulTokenBody($response);
        $access = $body['access_token'] ?? null;
        $refresh = $body['refresh_token'] ?? null;
        $expires = $body['expires_in'] ?? null;
        $nonce = $response->header('DPoP-Nonce');
        if (! is_string($access) || trim($access) === '' || ! is_string($refresh) || trim($refresh) === '' || ! is_numeric($expires) || (int) $expires < 1 || trim($nonce) === '') {
            throw new SocialAuthorizationException('token_response_invalid', 'The Bluesky refresh response did not rotate both tokens or omitted its DPoP nonce.');
        }
        $scopes = $this->parseScopes($body['scope'] ?? null);
        if (! in_array('atproto', $scopes, true) || ! in_array('repo:app.bsky.feed.post?action=create', $scopes, true) || ! in_array('blob:*/*', $scopes, true)) {
            throw new SocialAuthorizationException('scope_not_granted', 'The refreshed Bluesky session no longer grants create-only feed-post access and blob-upload permission.');
        }
        $context = $this->withNonce($context, (string) ($context['issuer'] ?? ''), $nonce);

        return new SocialTokenSet($access, $refresh, CarbonImmutable::now()->addSeconds((int) $expires), null, $scopes, 'DPoP', $current->destinationTokens, $context);
    }

    public function discover(SocialTokenSet $tokens, string $destinationNetwork): SocialDiscoveryResult
    {
        if ($destinationNetwork !== 'bsky') {
            throw new SocialAuthorizationException('destination_network_not_supported', 'Bluesky authorization can discover only Bluesky destinations.');
        }
        $expectedDid = $tokens->providerContext['expected_did'] ?? null;
        if (! is_string($expectedDid)) {
            throw new SocialAuthorizationException('authorization_context_invalid', 'The stored Bluesky identity context is incomplete.');
        }
        $response = $this->xrpc($tokens, 'GET', 'com.atproto.server.getSession');
        if (! $response->successful()) {
            throw $this->apiFailure($response, 'The Bluesky session could not be verified.');
        }
        $did = $response->json('did');
        $handle = $response->json('handle');
        if (! is_string($did) || ! hash_equals($expectedDid, $did) || ! is_string($handle) || ! $this->validHandle($handle)) {
            throw new SocialAuthorizationException('account_identity_mismatch', 'The Bluesky session identity did not match the DID resolved before authorization.');
        }

        return new SocialDiscoveryResult($did, $handle, [new SocialDiscoveredDestination('bsky', $did, $handle, 'https://bsky.app/profile/'.$handle)]);
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     * @param  array<string, mixed>|null  $body
     */
    public function xrpc(SocialTokenSet $tokens, string $method, string $nsid, array $parameters = [], ?array $body = null): Response
    {
        $context = $tokens->providerContext;
        $origin = $context['pds_origin'] ?? null;
        if (! is_string($origin) || ! is_string($context['private_key_pem'] ?? null) || ! $this->hasValidJwk($context['jwk'] ?? null)) {
            throw new SocialAuthorizationException('authorization_context_invalid', 'The stored Bluesky DPoP session context is incomplete.');
        }
        $url = $origin.'/xrpc/'.$nsid;
        if (strtoupper($method) === 'GET' && $parameters !== []) {
            $url .= '?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        }
        $nonce = $this->nonceFor($context, $origin);
        $response = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $proof = app(AtProtoDpopProof::class)->create($context['private_key_pem'], $this->validatedJwk($context['jwk']), $method, $url, $nonce, $tokens->accessToken);
            $request = Http::acceptJson()->timeout((int) config('social_authorization.timeout', 15))->withOptions(['allow_redirects' => false])->withHeaders(['Authorization' => 'DPoP '.$tokens->accessToken, 'DPoP' => $proof]);
            try {
                $response = match (strtoupper($method)) {
                    'GET' => $request->get($url), 'POST' => $request->asJson()->post($url, $body ?? []), default => throw new SocialAuthorizationException('http_method_unsupported', 'The AT Protocol request method is unsupported.')
                };
            } catch (ConnectionException $exception) {
                throw new SocialAuthorizationException('provider_timeout', 'The Bluesky service did not return a definitive response.', true, $exception);
            }
            $newNonce = $response->header('DPoP-Nonce');
            if (trim($newNonce) !== '') {
                $this->cacheNonce($context, $origin, $newNonce);
                if ($attempt === 0 && in_array($response->status(), [400, 401], true) && $newNonce !== $nonce) {
                    $nonce = $newNonce;

                    continue;
                }
            } else {
                throw new SocialAuthorizationException('dpop_nonce_missing', 'The Bluesky PDS response omitted its required DPoP nonce.');
            }
            break;
        }

        return $response;
    }

    /** @return array<string, mixed> */
    public function uploadBlob(SocialTokenSet $tokens, string $bytes, string $mimeType): array
    {
        if ($bytes === '' || ! in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw new SocialAuthorizationException('blob_input_invalid', 'Only non-empty JPEG and PNG blobs are supported.');
        }
        $context = $tokens->providerContext;
        $origin = $context['pds_origin'] ?? null;
        if (! is_string($origin) || ! is_string($context['private_key_pem'] ?? null) || ! $this->hasValidJwk($context['jwk'] ?? null)) {
            throw new SocialAuthorizationException('authorization_context_invalid', 'The stored Bluesky DPoP session context is incomplete.');
        }
        if (! in_array('blob:*/*', $tokens->scopes, true)) {
            throw new SocialAuthorizationException('scope_not_granted', 'The Bluesky session does not grant blob-upload permission.');
        }

        $url = $origin.'/xrpc/com.atproto.repo.uploadBlob';
        $nonce = $this->nonceFor($context, $origin);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $proof = app(AtProtoDpopProof::class)->create($context['private_key_pem'], $this->validatedJwk($context['jwk']), 'POST', $url, $nonce, $tokens->accessToken);
            try {
                $response = Http::acceptJson()
                    ->timeout((int) config('social_authorization.timeout', 15))
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders(['Authorization' => 'DPoP '.$tokens->accessToken, 'DPoP' => $proof, 'Content-Type' => $mimeType])
                    ->withBody($bytes, $mimeType)
                    ->post($url);
            } catch (ConnectionException $exception) {
                throw new SocialAuthorizationException('provider_timeout', 'The Bluesky blob upload did not return a definitive response.', true, $exception);
            }

            $newNonce = $response->header('DPoP-Nonce');
            if (trim($newNonce) === '') {
                throw new SocialAuthorizationException('dpop_nonce_missing', 'The Bluesky PDS response omitted its required DPoP nonce.');
            }
            $this->cacheNonce($context, $origin, $newNonce);
            if ($attempt === 0 && in_array($response->status(), [400, 401], true) && $newNonce !== $nonce) {
                $nonce = $newNonce;

                continue;
            }
            if (! $response->successful()) {
                throw $this->apiFailure($response, 'The Bluesky PDS rejected the image blob upload.');
            }
            $blob = $response->json('blob');
            if (! is_array($blob)) {
                throw new SocialAuthorizationException('blob_response_invalid', 'The Bluesky PDS returned an invalid blob response.');
            }

            return $blob;
        }

        throw new SocialAuthorizationException('blob_upload_failed', 'The Bluesky PDS could not complete the blob upload.');
    }

    public function resolveMentionDid(SocialTokenSet $tokens, string $handle): ?string
    {
        if (! $this->validHandle($handle)) {
            return null;
        }
        $response = $this->xrpc($tokens, 'GET', 'com.atproto.identity.resolveHandle', ['handle' => $handle]);
        $did = $response->successful() ? $response->json('did') : null;

        return is_string($did) && preg_match('/^did:(?:plc:[a-z0-9]+|web:.+)$/D', $did) === 1 ? $did : null;
    }

    /**
     * @param  array<string, scalar|null>  $parameters
     * @param  array<string, mixed>  $context
     */
    private function tokenRequest(string $url, array $parameters, array $context): Response
    {
        $origin = (string) ($context['issuer'] ?? '');
        $nonce = $this->nonceFor($context, $origin);
        $response = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $proof = app(AtProtoDpopProof::class)->create($context['private_key_pem'], $this->validatedJwk($context['jwk']), 'POST', $url, $nonce);
            try {
                $response = Http::asForm()->acceptJson()->timeout((int) config('social_authorization.timeout', 15))->withOptions(['allow_redirects' => false])->withHeaders(['DPoP' => $proof])->post($url, $parameters);
            } catch (ConnectionException $exception) {
                throw new SocialAuthorizationException('token_exchange_failed', 'The Bluesky authorization server could not complete the token request.', true, $exception);
            }
            $newNonce = $response->header('DPoP-Nonce');
            if (trim($newNonce) !== '') {
                $this->cacheNonce($context, $origin, $newNonce);
                if ($attempt === 0 && in_array($response->status(), [400, 401], true) && $newNonce !== $nonce) {
                    $nonce = $newNonce;

                    continue;
                }
            }
            break;
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function successfulTokenBody(Response $response): array
    {
        if (! $response->successful()) {
            $error = $response->json('error');
            $known = is_string($error) && in_array($error, ['invalid_grant', 'invalid_client', 'invalid_token', 'unauthorized_client'], true) ? $error : 'token_request_rejected';
            throw new SocialAuthorizationException($known, 'The Bluesky authorization server rejected the token request. Reconnect the account if its session has expired.', in_array($response->status(), [429, 500, 502, 503, 504], true));
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new SocialAuthorizationException('token_response_invalid', 'The Bluesky authorization server returned invalid JSON.');
        }

        return $body;
    }

    /** @return array{did: string, handle: ?string, pds_origin: string} */
    private function resolveIdentity(string $identifier): array
    {
        $requestedHandle = null;
        if (preg_match('/^did:(?:plc:[a-z0-9]+|web:.+)$/D', $identifier) === 1) {
            $did = $identifier;
            $document = $this->fetchDidDocument($did);
        } else {
            if (! $this->validHandle($identifier)) {
                throw new SocialAuthorizationException('account_identifier_invalid', 'Enter a valid Bluesky handle or DID.');
            }
            $requestedHandle = strtolower($identifier);
            $did = null;
            $document = null;
            foreach ($this->resolveHandleCandidates($requestedHandle) as $candidate) {
                try {
                    $candidateDocument = $this->fetchDidDocument($candidate);
                } catch (SocialAuthorizationException) {
                    continue;
                }
                $aliases = $candidateDocument['alsoKnownAs'] ?? [];
                if (($candidateDocument['id'] ?? null) !== $candidate || ! is_array($aliases)
                    || ! in_array('at://'.$requestedHandle, array_map('strtolower', array_filter($aliases, 'is_string')), true)) {
                    continue;
                }
                $did = $candidate;
                $document = $candidateDocument;
                break;
            }
            if (! is_string($did) || ! is_array($document)) {
                throw new SocialAuthorizationException('handle_resolution_failed', 'The Bluesky handle could not be resolved to a DID document that verifies the handle.');
            }
        }

        if (($document['id'] ?? null) !== $did) {
            throw new SocialAuthorizationException('did_document_invalid', 'The DID document does not match the requested account identity.');
        }
        $aliases = $document['alsoKnownAs'] ?? [];
        $pds = null;
        foreach (is_array($document['service'] ?? null) ? $document['service'] : [] as $service) {
            if (is_array($service) && (($service['type'] ?? null) === 'AtprotoPersonalDataServer' || str_ends_with((string) ($service['id'] ?? ''), '#atproto_pds')) && is_string($service['serviceEndpoint'] ?? null)) {
                $pds = $service['serviceEndpoint'];
                break;
            }
        }
        if (! is_string($pds)) {
            throw new SocialAuthorizationException('pds_discovery_failed', 'The DID document does not advertise a supported personal data server.');
        }
        $pdsOrigin = $this->assertOrigin($pds, 'personal data server');
        $handle = $requestedHandle;
        if ($handle === null && is_array($aliases)) {
            foreach ($aliases as $alias) {
                if (is_string($alias) && preg_match('~^at://([a-z0-9.-]+)$~iD', $alias, $matches) === 1) {
                    $handle = strtolower($matches[1]);
                    break;
                }
            }
        }

        return ['did' => $did, 'handle' => $handle, 'pds_origin' => $pdsOrigin];
    }

    /** @return \Generator<int, string> */
    private function resolveHandleCandidates(string $handle): \Generator
    {
        $seen = [];
        try {
            $resolved = $this->fetchJson('https://bsky.social/xrpc/com.atproto.identity.resolveHandle?'.http_build_query(['handle' => $handle], '', '&', PHP_QUERY_RFC3986));
            $did = $resolved['did'] ?? null;
            if (is_string($did) && preg_match('/^did:(?:plc:[a-z0-9]+|web:.+)$/D', $did) === 1) {
                $seen[$did] = true;
                yield $did;
            }
        } catch (SocialAuthorizationException) {
            // Try the handle's own HTTPS and DNS declarations below.
        }

        try {
            if (! app()->environment('testing')) {
                $this->assertPublicHost($handle);
            }
            $response = Http::accept('text/plain')->timeout((int) config('social_authorization.timeout', 15))
                ->withOptions(['allow_redirects' => false])->get('https://'.$handle.'/.well-known/atproto-did');
            if ($response->successful()) {
                $did = trim($response->body());
                if (preg_match('/^did:(?:plc:[a-z0-9]+|web:.+)$/D', $did) === 1 && ! isset($seen[$did])) {
                    $seen[$did] = true;
                    yield $did;
                }
            }
        } catch (ConnectionException|SocialAuthorizationException) {
            // DNS TXT resolution remains available if the HTTPS declaration cannot be read.
        }

        if (! app()->environment('testing')) {
            $records = dns_get_record('_atproto.'.$handle, DNS_TXT);
            foreach (is_array($records) ? $records : [] as $record) {
                $txt = $record['txt'] ?? null;
                if (is_string($txt) && preg_match('/(?:^|\s)did=(did:(?:plc:[a-z0-9]+|web:.+?))(?:\s|$)/D', $txt, $matches) === 1 && ! isset($seen[$matches[1]])) {
                    $seen[$matches[1]] = true;
                    yield $matches[1];
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function fetchDidDocument(string $did): array
    {
        if (str_starts_with($did, 'did:plc:')) {
            return $this->fetchJson('https://plc.directory/'.$did);
        }
        if (str_starts_with($did, 'did:web:')) {
            $parts = explode(':', substr($did, 8));
            $host = rawurldecode((string) array_shift($parts));
            if (! app()->environment('testing')) {
                $this->assertPublicHost($host);
            }
            $path = $parts === [] ? '/.well-known/did.json' : '/'.implode('/', array_map('rawurlencode', $parts)).'/did.json';

            return $this->fetchJson('https://'.$host.$path);
        }
        throw new SocialAuthorizationException('did_method_unsupported', 'Only publicly resolvable did:plc and did:web identities are supported.');
    }

    /** @return array<string, mixed> */
    private function fetchJson(string $url): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw new SocialAuthorizationException('provider_url_invalid', 'An AT Protocol metadata endpoint is not a valid HTTPS URL.');
        }
        if (! app()->environment('testing')) {
            $this->assertPublicHost($parts['host']);
        }
        try {
            $response = Http::acceptJson()->timeout((int) config('social_authorization.timeout', 15))->withOptions(['allow_redirects' => false])->get($url);
        } catch (ConnectionException $exception) {
            throw new SocialAuthorizationException('provider_unavailable', 'The AT Protocol identity or authorization service is temporarily unavailable.', true, $exception);
        }
        if (! $response->successful()) {
            throw new SocialAuthorizationException('provider_metadata_unavailable', 'The AT Protocol identity or authorization service returned an unsuccessful metadata response.', $response->status() >= 500 || $response->status() === 429);
        }
        $body = $response->json();
        if (! is_array($body)) {
            throw new SocialAuthorizationException('provider_metadata_invalid', 'The AT Protocol identity or authorization service returned invalid JSON.');
        }

        return $body;
    }

    private function assertPublicHost(string $host): void
    {
        if ($host === '' || str_contains($host, '/') || str_contains($host, '@') || strtolower($host) === 'localhost' || str_ends_with(strtolower($host), '.localhost')) {
            throw new SocialAuthorizationException('provider_host_unsafe', 'Private or local hosts cannot be used as AT Protocol endpoints.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new SocialAuthorizationException('provider_host_unsafe', 'Private or reserved IP addresses cannot be used as AT Protocol endpoints.');
            }

            return;
        }
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            throw new SocialAuthorizationException('provider_host_unresolvable', 'The AT Protocol endpoint host could not be resolved safely.');
        }
        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new SocialAuthorizationException('provider_host_unsafe', 'Private or reserved IP addresses cannot be used as AT Protocol endpoints.');
            }
        }
    }

    private function assertClientUri(string $clientId, string $clientUri): void
    {
        $clientIdParts = parse_url($clientId);
        $clientUriParts = parse_url($clientUri);
        if (! is_array($clientIdParts) || ! is_string($clientIdParts['host'] ?? null)
            || ! is_array($clientUriParts) || ! is_string($clientUriParts['host'] ?? null)
            || strtolower($clientIdParts['host']) !== strtolower($clientUriParts['host'])) {
            throw new SocialAuthorizationException('client_metadata_invalid', 'The AT Protocol client homepage must use the same hostname as the client metadata URL.');
        }
    }

    private function assertClientMetadataUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new SocialAuthorizationException('client_metadata_url_invalid', 'The AT Protocol client ID must be a public HTTPS metadata URL without a port, credentials, query or fragment.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function metadataEndpoint(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;
        if (! is_string($value)) {
            throw new SocialAuthorizationException('authorization_server_unsupported', 'The authorization server omitted a required OAuth endpoint.');
        }

        return $this->assertHttpsUrl($value, $key);
    }

    private function assertOrigin(string $url, string $label): string
    {
        $this->assertHttpsUrl($url, $label);
        $parts = parse_url($url);
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') || (isset($parts['port']) && $parts['port'] === 443)) {
            throw new SocialAuthorizationException('provider_origin_invalid', "The AT Protocol {$label} must be a simple HTTPS origin.");
        }

        return 'https://'.strtolower((string) $parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function assertHttpsUrl(string $url, string $label): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw new SocialAuthorizationException('provider_url_invalid', "The AT Protocol {$label} must use HTTPS.");
        }
        if (! app()->environment('testing')) {
            $this->assertPublicHost($parts['host']);
        }

        return $url;
    }

    private function requiredHttpsUrl(string $key): string
    {
        $value = $this->configuration[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new SocialAuthorizationException('provider_not_configured', 'The Bluesky OAuth client configuration is incomplete.');
        }

        return $this->assertHttpsUrl($value, $key);
    }

    private function validHandle(string $handle): bool
    {
        return strlen($handle) <= 253 && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/iD', $handle) === 1 && filter_var($handle, FILTER_VALIDATE_IP) === false && ! str_ends_with(strtolower($handle), '.localhost');
    }

    /** @return list<string> */
    private function parseScopes(mixed $scopes): array
    {
        if (is_array($scopes)) {
            return array_values(array_unique(array_filter($scopes, 'is_string')));
        }
        if (is_string($scopes)) {
            return array_values(array_unique(preg_split('/\s+/', trim($scopes)) ?: []));
        }

        return [];
    }

    private function apiFailure(Response $response, string $message): SocialAuthorizationException
    {
        if (in_array($response->status(), [401, 403], true)) {
            return new SocialAuthorizationException('invalid_token', 'The Bluesky session was rejected. Reconnect if authorization has been revoked.');
        }
        if ($response->status() === 429) {
            return new SocialAuthorizationException('rate_limited', 'The Bluesky rate limit was reached. Retry after the provider backoff window.', true);
        }

        return new SocialAuthorizationException('provider_request_failed', $message, $response->status() >= 500);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withNonce(array $context, string $origin, string $nonce): array
    {
        $context['dpop_nonces'][$origin] = $nonce;
        $this->cacheNonce($context, $origin, $nonce);

        return $context;
    }

    /** @param array<string, mixed> $context */
    private function nonceFor(array $context, string $origin): ?string
    {
        $cached = Cache::get($this->nonceCacheKey($context, $origin));
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $nonce = $context['dpop_nonces'][$origin] ?? null;

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /** @param array<string, mixed> $context */
    private function cacheNonce(array $context, string $origin, string $nonce): void
    {
        Cache::put($this->nonceCacheKey($context, $origin), $nonce, now()->addMinutes(5));
    }

    /** @param array<string, mixed> $context */
    private function nonceCacheKey(array $context, string $origin): string
    {
        return 'atproto-dpop-nonce:'.hash('sha256', (string) ($context['dpop_thumbprint'] ?? '').'|'.$origin);
    }

    private function hasValidJwk(mixed $value): bool
    {
        return is_array($value)
            && ($value['kty'] ?? null) === 'EC'
            && ($value['crv'] ?? null) === 'P-256'
            && is_string($value['x'] ?? null)
            && is_string($value['y'] ?? null);
    }

    /** @return array{kty: string, crv: string, x: string, y: string} */
    private function validatedJwk(mixed $value): array
    {
        if (! $this->hasValidJwk($value)) {
            throw new SocialAuthorizationException('authorization_context_invalid', 'The stored Bluesky DPoP public key is invalid.');
        }

        return ['kty' => 'EC', 'crv' => 'P-256', 'x' => $value['x'], 'y' => $value['y']];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
