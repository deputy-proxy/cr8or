<?php

use App\Data\Social\SocialTokenSet;
use App\Exceptions\SocialAuthorizationException;
use App\Services\Social\AtProtocolOAuthProvider;
use App\Services\Social\AtProtoDpopProof;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function atProtocolOAuthProvider(): AtProtocolOAuthProvider
{
    return new AtProtocolOAuthProvider([
        'client_id' => 'https://cr8or.example.test/oauth-client-metadata.json',
        'client_name' => 'CR8OR',
        'client_uri' => 'https://cr8or.example.test',
        'redirect_uri' => 'https://cr8or.example.test/social/authorization/callback/bluesky',
    ]);
}

function atProtocolAuthorizationServerMetadata(array $overrides = []): array
{
    return array_replace([
        'issuer' => 'https://auth.example.test',
        'authorization_endpoint' => 'https://auth.example.test/oauth/authorize',
        'token_endpoint' => 'https://auth.example.test/oauth/token',
        'pushed_authorization_request_endpoint' => 'https://auth.example.test/oauth/par',
        'client_id_metadata_document_supported' => true,
        'require_pushed_authorization_requests' => true,
        'authorization_response_iss_parameter_supported' => true,
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
        'token_endpoint_auth_signing_alg_values_supported' => ['ES256'],
        'scopes_supported' => ['atproto'],
        'dpop_signing_alg_values_supported' => ['ES256'],
    ], $overrides);
}

function atProtocolDpopTokens(array $contextOverrides = []): SocialTokenSet
{
    $keyPair = app(AtProtoDpopProof::class)->generateKeyPair();

    return new SocialTokenSet(
        accessToken: 'old-access-token',
        refreshToken: 'old-refresh-token',
        accessTokenExpiresAt: CarbonImmutable::now()->subMinute(),
        refreshTokenExpiresAt: null,
        scopes: ['atproto', 'repo:app.bsky.feed.post?action=create', 'blob:*/*'],
        tokenType: 'DPoP',
        providerContext: array_replace([
            'expected_did' => 'did:plc:abcdefghijklmnopqrstuvwx',
            'expected_handle' => 'alice.bsky.social',
            'pds_origin' => 'https://pds.example.test',
            'issuer' => 'https://auth.example.test',
            'token_endpoint' => 'https://auth.example.test/oauth/token',
            'private_key_pem' => $keyPair['private_key_pem'],
            'jwk' => $keyPair['jwk'],
            'dpop_thumbprint' => $keyPair['thumbprint'],
            'dpop_nonces' => [],
        ], $contextOverrides),
    );
}

it('discovers the account PDS and authorization server and initiates a PKCE plus DPoP PAR flow', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:abcdefghijklmnopqrstuvwx']),
        'https://plc.directory/did:plc:abcdefghijklmnopqrstuvwx' => Http::response([
            'id' => 'did:plc:abcdefghijklmnopqrstuvwx',
            'alsoKnownAs' => ['at://alice.bsky.social'],
            'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.test']],
        ]),
        'https://pds.example.test/.well-known/oauth-protected-resource' => Http::response(['resource' => 'https://pds.example.test', 'authorization_servers' => ['https://auth.example.test']]),
        'https://auth.example.test/.well-known/oauth-authorization-server' => Http::response(atProtocolAuthorizationServerMetadata()),
        'https://auth.example.test/oauth/par' => Http::response(['request_uri' => 'urn:ietf:params:oauth:request_uri:one-time'], 201, ['DPoP-Nonce' => 'par-nonce']),
    ]);

    $initiation = atProtocolOAuthProvider()->initiate(
        'random-state',
        'https://cr8or.example.test/social/authorization/callback/bluesky',
        ['atproto', 'repo:app.bsky.feed.post?action=create', 'blob:*/*'],
        'pkce-verifier',
        'alice.bsky.social',
    );

    expect($initiation->url)->toStartWith('https://auth.example.test/oauth/authorize?')
        ->and($initiation->url)->toContain('request_uri=urn%3Aietf%3Aparams%3Aoauth%3Arequest_uri%3Aone-time')
        ->and($initiation->context['expected_did'])->toBe('did:plc:abcdefghijklmnopqrstuvwx')
        ->and($initiation->context['expected_handle'])->toBe('alice.bsky.social')
        ->and($initiation->context['pds_origin'])->toBe('https://pds.example.test')
        ->and($initiation->context['dpop_nonces']['https://auth.example.test'])->toBe('par-nonce');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.test/oauth/par'
        && $request->method() === 'POST'
        && $request['client_id'] === 'https://cr8or.example.test/oauth-client-metadata.json'
        && $request['code_challenge_method'] === 'S256'
        && $request['state'] === 'random-state'
        && $request->hasHeader('DPoP'));
});

it('fails closed when the authorization server does not require PAR or issuer validation', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:abcdefghijklmnopqrstuvwx']),
        'https://plc.directory/did:plc:abcdefghijklmnopqrstuvwx' => Http::response([
            'id' => 'did:plc:abcdefghijklmnopqrstuvwx',
            'alsoKnownAs' => ['at://alice.bsky.social'],
            'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.test']],
        ]),
        'https://pds.example.test/.well-known/oauth-protected-resource' => Http::response(['resource' => 'https://pds.example.test', 'authorization_servers' => ['https://auth.example.test']]),
        'https://auth.example.test/.well-known/oauth-authorization-server' => Http::response(atProtocolAuthorizationServerMetadata(['require_pushed_authorization_requests' => false])),
    ]);

    expect(fn () => atProtocolOAuthProvider()->initiate(
        'random-state',
        'https://cr8or.example.test/social/authorization/callback/bluesky',
        ['atproto', 'repo:app.bsky.feed.post?action=create', 'blob:*/*'],
        'pkce-verifier',
        'alice.bsky.social',
    ))->toThrow(SocialAuthorizationException::class, 'security profile');

    Http::assertSentCount(4);
});

it('keeps the DID stable while discovering the account current handle', function () {
    $tokens = atProtocolDpopTokens();
    Http::fake([
        'https://pds.example.test/xrpc/com.atproto.server.getSession' => Http::response([
            'did' => 'did:plc:abcdefghijklmnopqrstuvwx',
            'handle' => 'alice-new.bsky.social',
        ], 200, ['DPoP-Nonce' => 'pds-session-nonce']),
    ]);

    $discovery = atProtocolOAuthProvider()->discover($tokens, 'bsky');

    expect($discovery->identityExternalId)->toBe('did:plc:abcdefghijklmnopqrstuvwx')
        ->and($discovery->identityName)->toBe('alice-new.bsky.social')
        ->and($discovery->destinations[0]->externalId)->toBe('did:plc:abcdefghijklmnopqrstuvwx')
        ->and($discovery->destinations[0]->name)->toBe('alice-new.bsky.social');
});

it('rotates both OAuth tokens on refresh and preserves the session DPoP identity', function () {
    $tokens = atProtocolDpopTokens();
    Http::fake([
        'https://auth.example.test/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'scope' => 'atproto repo:app.bsky.feed.post?action=create blob:*/*',
        ], 200, ['DPoP-Nonce' => 'rotated-nonce']),
    ]);

    $refreshed = atProtocolOAuthProvider()->refresh($tokens);

    expect($refreshed->accessToken)->toBe('new-access-token')
        ->and($refreshed->refreshToken)->toBe('new-refresh-token')
        ->and($refreshed->tokenType)->toBe('DPoP')
        ->and($refreshed->providerContext['dpop_thumbprint'])->toBe($tokens->providerContext['dpop_thumbprint'])
        ->and($refreshed->providerContext['dpop_nonces']['https://auth.example.test'])->toBe('rotated-nonce');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.test/oauth/token'
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh-token'
        && $request->hasHeader('DPoP'));
});

it('requires the refreshed session to retain create-only post permissions', function () {
    Http::fake([
        'https://auth.example.test/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'scope' => 'atproto',
        ], 200, ['DPoP-Nonce' => 'rotated-nonce']),
    ]);

    expect(fn () => atProtocolOAuthProvider()->refresh(atProtocolDpopTokens()))
        ->toThrow(SocialAuthorizationException::class, 'no longer grants create-only feed-post access');
});

it('rejects an OAuth callback issuer that differs from the account authorization server', function () {
    $tokens = atProtocolDpopTokens();
    $provider = atProtocolOAuthProvider();

    expect(fn () => $provider->exchangeWithContext(
        'authorization-code',
        'https://cr8or.example.test/social/authorization/callback/bluesky',
        'pkce-verifier',
        $tokens->providerContext,
        ['iss' => 'https://attacker.example.test'],
    ))->toThrow(SocialAuthorizationException::class, 'issuer does not match');

    Http::assertNothingSent();
});

it('requires an invalid refresh grant to be reauthorized rather than silently reusing stale tokens', function () {
    Http::fake([
        'https://auth.example.test/oauth/token' => Http::response(['error' => 'invalid_grant'], 400, ['DPoP-Nonce' => 'refresh-nonce']),
    ]);

    expect(fn () => atProtocolOAuthProvider()->refresh(atProtocolDpopTokens()))
        ->toThrow(SocialAuthorizationException::class, 'rejected the token request');
});

it('resolves custom handles through their well-known DID declaration when the Bluesky XRPC resolver cannot resolve them', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_contains($url, '/xrpc/com.atproto.identity.resolveHandle')) {
            return Http::response(['error' => 'HandleNotFound'], 400);
        }
        if ($url === 'https://alice.custom.example/.well-known/atproto-did') {
            return Http::response('did:plc:abcdefghijklmnopqrstuvwx', 200, ['Content-Type' => 'text/plain']);
        }
        if ($url === 'https://plc.directory/did:plc:abcdefghijklmnopqrstuvwx') {
            return Http::response([
                'id' => 'did:plc:abcdefghijklmnopqrstuvwx',
                'alsoKnownAs' => ['at://alice.custom.example'],
                'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.test']],
            ]);
        }
        if ($url === 'https://pds.example.test/.well-known/oauth-protected-resource') {
            return Http::response(['resource' => 'https://pds.example.test', 'authorization_servers' => ['https://auth.example.test']]);
        }
        if ($url === 'https://auth.example.test/.well-known/oauth-authorization-server') {
            return Http::response(atProtocolAuthorizationServerMetadata());
        }
        if ($url === 'https://auth.example.test/oauth/par') {
            return Http::response(['request_uri' => 'urn:ietf:params:oauth:request_uri:custom-handle'], 201, ['DPoP-Nonce' => 'par-nonce-custom']);
        }

        return Http::response([], 404);
    });

    $initiation = atProtocolOAuthProvider()->initiate(
        'state-custom',
        'https://cr8or.example.test/social/authorization/callback/bluesky',
        ['atproto', 'repo:app.bsky.feed.post?action=create', 'blob:*/*'],
        'verifier-custom',
        'alice.custom.example',
    );

    expect($initiation->context['expected_did'])->toBe('did:plc:abcdefghijklmnopqrstuvwx')
        ->and($initiation->context['expected_handle'])->toBe('alice.custom.example')
        ->and($initiation->context['pds_origin'])->toBe('https://pds.example.test');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://alice.custom.example/.well-known/atproto-did');
});

it('rejects a callback URI on a different origin than the client metadata document', function () {
    config(['social_authorization.providers.bluesky.redirect_uri' => 'https://attacker.example.test/social/authorization/callback/bluesky']);

    $provider = new AtProtocolOAuthProvider([
        'client_id' => 'https://cr8or.example.test/oauth-client-metadata.json',
        'client_name' => 'CR8OR',
        'client_uri' => 'https://cr8or.example.test',
        'redirect_uri' => 'https://attacker.example.test/social/authorization/callback/bluesky',
    ]);

    expect(fn () => $provider->initiate(
        'random-state',
        'https://attacker.example.test/social/authorization/callback/bluesky',
        ['atproto', 'repo:app.bsky.feed.post?action=create', 'blob:*/*'],
        'pkce-verifier',
        'alice.bsky.social',
    ))->toThrow(SocialAuthorizationException::class, 'same HTTPS origin');

    Http::assertNothingSent();
});
