<?php

use App\Data\Social\SocialTokenSet;
use App\Exceptions\SocialAuthorizationException;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\SocialAuthorizationAttempt;
use App\Models\SocialAuthorizationAudit;
use App\Models\SocialCredential;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Providers\SocialCredentialResolver;
use App\Services\Social\SocialAuthorizationService;
use App\Services\Social\SocialCredentialManager;
use App\Services\Social\SocialCredentialStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

function configureLinkedInAuthorization(): void
{
    config([
        'social_authorization.network_to_provider.linkedin' => 'linkedin',
        'social_authorization.attempt_ttl_minutes' => 10,
        'social_authorization.providers.linkedin' => [
            'driver' => 'oauth2',
            'enabled' => true,
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect_uri' => 'https://cr8or.test/social/authorization/callback/linkedin',
            'authorization_url' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
            'client_auth_method' => 'body',
            'pkce' => false,
            'authorization_parameters' => [],
            'scopes' => ['openid', 'profile'],
            'destination_networks' => ['linkedin'],
            'identity' => [
                'url' => 'https://api.linkedin.com/v2/userinfo',
                'id_path' => 'sub',
                'name_path' => 'name',
            ],
            'destinations' => [[
                'network' => 'linkedin',
                'url' => 'https://api.linkedin.com/v2/userinfo',
                'path' => '',
                'id_path' => 'sub',
                'id_prefix' => 'urn:li:person:',
                'name_path' => 'name',
                'url_path' => null,
            ], [
                'network' => 'linkedin',
                'url' => 'https://api.linkedin.com/rest/organizationalEntityAcls?q=roleAssignee',
                'query' => ['roleAssignee' => 'urn:li:person:{identity_id}'],
                'path' => 'elements',
                'id_path' => 'organizationalTarget',
                'name_path' => 'organizationalTarget',
                'url_path' => null,
                'metadata' => [
                    'url' => 'https://api.linkedin.com/rest/organizationsLookup',
                    'query' => ['ids' => 'List({destination_id})'],
                    'name_path' => 'results.{destination_id}.localizedName',
                    'url_path' => 'results.{destination_id}.vanityName',
                    'url_prefix' => 'https://www.linkedin.com/company/',
                ],
            ]],
            'token_response' => [
                'access_token' => 'access_token',
                'refresh_token' => 'refresh_token',
                'expires_in' => 'expires_in',
                'refresh_expires_in' => null,
                'scope' => 'scope',
            ],
            'refresh' => ['strategy' => 'oauth2'],
            'revoke' => ['url' => null],
        ],
    ]);

    Gate::before(fn (): bool => true);
}

function fakeLinkedInAuthorizationResponses(bool $metadataAvailable = true): void
{
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'access-secret-value',
            'refresh_token' => 'refresh-secret-value',
            'expires_in' => 3600,
            'scope' => 'openid profile',
        ]),
        'https://api.linkedin.com/v2/userinfo' => Http::response([
            'sub' => 'opaque-member-id',
            'name' => 'Test Member',
        ]),
        'https://api.linkedin.com/rest/organizationalEntityAcls*' => Http::response([
            'elements' => [['organizationalTarget' => 'urn:li:organization:123']],
        ]),
        'https://api.linkedin.com/rest/organizationsLookup*' => $metadataAvailable
            ? Http::response(['results' => ['123' => ['localizedName' => 'Test Organization', 'vanityName' => 'test-organization']]])
            : Http::response(['message' => 'permission denied'], 403),
    ]);
}

it('completes a single-use authorization and stores only encrypted credential material', function (): void {
    configureLinkedInAuthorization();
    fakeLinkedInAuthorizationResponses();

    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $service = app(SocialAuthorizationService::class);

    $authorizationUrl = $service->start($actor, (int) $organization->id, 'linkedin');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    expect($query['state'] ?? null)->toBeString()->not->toBeEmpty();

    $attempt = SocialAuthorizationAttempt::query()->where('organization_id', $organization->id)->sole();
    expect($attempt->state_hash)->toBe(hash('sha256', $query['state']))
        ->and(Crypt::decryptString($attempt->code_verifier_encrypted))->not->toBe('access-secret-value');

    $completed = $service->complete($actor, 'linkedin', $query['state'], 'authorization-code');

    expect($completed->status)->toBe(SocialAuthorizationAttempt::STATUS_COMPLETED)
        ->and(SocialIdentity::query()->where('organization_id', $organization->id)->where('external_id', 'opaque-member-id')->exists())->toBeTrue()
        ->and(SocialCredential::query()->where('organization_id', $organization->id)->count())->toBe(1)
        ->and($completed->discovered_destinations)->toContain([
            'provider' => 'linkedin',
            'external_id' => 'urn:li:person:opaque-member-id',
            'name' => 'Test Member',
            'url' => null,
        ])
        ->and($completed->discovered_destinations)->toContain([
            'provider' => 'linkedin',
            'external_id' => 'urn:li:organization:123',
            'name' => 'Test Organization',
            'url' => 'https://www.linkedin.com/company/test-organization',
        ]);

    Http::assertSent(function ($request): bool {
        if (! str_starts_with($request->url(), 'https://api.linkedin.com/rest/organizationalEntityAcls')) {
            return false;
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['roleAssignee'] ?? null) === 'urn:li:person:opaque-member-id';
    });

    $credential = SocialCredential::query()->where('organization_id', $organization->id)->sole();
    expect($credential->tokenSet()->accessToken)->toBe('access-secret-value')
        ->and($credential->getRawOriginal('encrypted_payload'))->not->toContain('access-secret-value')
        ->and(SocialAuthorizationAudit::query()->where('organization_id', $organization->id)->where('action', 'authorization_completed')->exists())->toBeTrue();

    expect(fn () => $service->complete($actor, 'linkedin', $query['state'], 'authorization-code'))
        ->toThrow(SocialAuthorizationException::class);
});

it('keeps LinkedIn authorization usable when optional organization display metadata is denied', function (): void {
    configureLinkedInAuthorization();
    fakeLinkedInAuthorizationResponses(metadataAvailable: false);

    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $service = app(SocialAuthorizationService::class);
    $authorizationUrl = $service->start($actor, (int) $organization->id, 'linkedin');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    $completed = $service->complete($actor, 'linkedin', $query['state'], 'authorization-code');

    expect($completed->status)->toBe(SocialAuthorizationAttempt::STATUS_COMPLETED)
        ->and($completed->discovered_destinations)->toContain([
            'provider' => 'linkedin',
            'external_id' => 'urn:li:organization:123',
            'name' => 'urn:li:organization:123',
            'url' => null,
        ]);
});

it('rejects callbacks from a different actor before exchanging credentials', function (): void {
    configureLinkedInAuthorization();
    Http::fake();

    $actor = User::factory()->create();
    $otherActor = User::factory()->create();
    $organization = Organization::factory()->create();
    $service = app(SocialAuthorizationService::class);

    $authorizationUrl = $service->start($actor, (int) $organization->id, 'linkedin');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    expect(fn () => $service->complete($otherActor, 'linkedin', $query['state'], 'authorization-code'))
        ->toThrow(SocialAuthorizationException::class);

    Http::assertNothingSent();
    expect(SocialAuthorizationAttempt::query()->sole()->status)->toBe(SocialAuthorizationAttempt::STATUS_PENDING);
});

it('records provider denial without requesting or storing tokens', function (): void {
    configureLinkedInAuthorization();
    Http::fake();

    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $service = app(SocialAuthorizationService::class);

    $authorizationUrl = $service->start($actor, (int) $organization->id, 'linkedin');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    $attempt = $service->complete($actor, 'linkedin', $query['state'], null, 'access_denied');

    expect($attempt->status)->toBe(SocialAuthorizationAttempt::STATUS_DENIED)
        ->and($attempt->failure_code)->toBe('access_denied')
        ->and(SocialCredential::query()->count())->toBe(0);

    Http::assertNothingSent();
});

function createLinkedInConnectionWithTokens(Organization $organization, string $accessToken, string $refreshToken, CarbonImmutable $expiresAt): IntegrationConnection
{
    $identity = SocialIdentity::factory()->create([
        'organization_id' => $organization->id,
        'provider' => 'linkedin',
        'external_id' => 'refresh-test-member',
    ]);
    $reference = app(SocialCredentialStore::class)->store($organization, 'linkedin', new SocialTokenSet(
        $accessToken,
        $refreshToken,
        $expiresAt,
        null,
        ['openid', 'profile'],
    ));

    return IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'social_identity_id' => $identity->id,
        'provider' => 'linkedin',
        'external_account_id' => $identity->external_id,
        'credential_reference' => $reference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
}

it('rotates access and refresh tokens under the credential refresh lock', function (): void {
    configureLinkedInAuthorization();
    config(['social_authorization.providers.linkedin.refresh' => ['strategy' => 'oauth2']]);
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response([
            'access_token' => 'rotated-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 3600,
            'scope' => 'openid profile',
        ]),
    ]);

    $organization = Organization::factory()->create();
    $connection = createLinkedInConnectionWithTokens($organization, 'old-access-token', 'old-refresh-token', CarbonImmutable::now()->subMinute());

    expect(app(SocialCredentialManager::class)->resolveAccessToken($connection))->toBe('rotated-access-token');

    $credential = app(SocialCredentialStore::class)->forConnection($connection->refresh());
    expect($credential->tokenSet()->accessToken)->toBe('rotated-access-token')
        ->and($credential->tokenSet()->refreshToken)->toBe('rotated-refresh-token')
        ->and($connection->status)->toBe(IntegrationConnection::STATUS_ACTIVE);
});

it('requires reconnection after an invalid refresh grant and never exposes tokens in audit metadata', function (): void {
    configureLinkedInAuthorization();
    config(['social_authorization.providers.linkedin.refresh' => ['strategy' => 'oauth2']]);
    Http::fake([
        'https://www.linkedin.com/oauth/v2/accessToken' => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $organization = Organization::factory()->create();
    $connection = createLinkedInConnectionWithTokens($organization, 'old-access-token', 'old-refresh-token', CarbonImmutable::now()->subMinute());

    expect(fn () => app(SocialCredentialManager::class)->resolveAccessToken($connection))
        ->toThrow(SocialAuthorizationException::class);

    expect($connection->refresh()->status)->toBe(IntegrationConnection::STATUS_RECONNECT_REQUIRED);
    $audit = SocialAuthorizationAudit::query()->where('action', 'credential_refresh_failed')->sole();
    expect(json_encode($audit->metadata))->not->toContain('old-access-token')
        ->and(json_encode($audit->metadata))->not->toContain('old-refresh-token');
});

it('does not resolve a social credential for a network outside the grant', function (): void {
    configureLinkedInAuthorization();
    $organization = Organization::factory()->create();
    $connection = createLinkedInConnectionWithTokens($organization, 'access-token', 'refresh-token', CarbonImmutable::now()->addHour());

    expect(fn () => app(SocialCredentialResolver::class)->resolveAccessToken($connection, 'facebook'))
        ->toThrow(SocialAuthorizationException::class);
});

it('expires authorization attempts and rejects later callbacks', function (): void {
    configureLinkedInAuthorization();
    Http::fake();

    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $service = app(SocialAuthorizationService::class);
    $authorizationUrl = $service->start($actor, (int) $organization->id, 'linkedin');
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $query);

    DB::table('social_authorization_attempts')->where('state_hash', hash('sha256', $query['state']))->update(['expires_at' => now()->subMinute()]);

    expect(fn () => $service->complete($actor, 'linkedin', $query['state'], 'authorization-code'))
        ->toThrow(SocialAuthorizationException::class);

    expect(SocialAuthorizationAttempt::query()->sole()->status)->toBe(SocialAuthorizationAttempt::STATUS_EXPIRED);
    Http::assertNothingSent();
});

it('keeps a connection locally disabled when remote revocation cannot be confirmed', function (): void {
    configureLinkedInAuthorization();
    config(['social_authorization.providers.linkedin.revoke' => [
        'url' => 'https://www.linkedin.com/oauth/v2/revoke',
        'method' => 'POST',
        'parameter' => 'token',
    ]]);
    Http::fake(['https://www.linkedin.com/oauth/v2/revoke' => Http::response(['error' => 'temporarily_unavailable'], 503)]);

    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    $connection = createLinkedInConnectionWithTokens($organization, 'access-token-secret', 'refresh-token-secret', CarbonImmutable::now()->addHour());
    $credential = app(SocialCredentialStore::class)->forConnection($connection);

    $result = app(SocialCredentialManager::class)->disconnect($connection, (int) $actor->id);

    expect($result->value)->toBe('failed')
        ->and($connection->refresh()->status)->toBe(IntegrationConnection::STATUS_DISABLED)
        ->and(SocialCredential::query()->whereKey($credential->id)->exists())->toBeTrue();

    $audit = SocialAuthorizationAudit::query()->where('action', 'connection_disconnected')->sole();
    expect(json_encode($audit->metadata))->not->toContain('access-token-secret')
        ->and(json_encode($audit->metadata))->not->toContain('refresh-token-secret');
});