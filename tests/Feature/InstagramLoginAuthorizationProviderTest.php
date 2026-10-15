<?php

use App\Data\Social\SocialTokenSet;
use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\SocialAuthorizationAttempt;
use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\Social\SocialAuthorizationService;
use App\Services\Social\SocialCredentialStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

function configureInstagramLogin(): void
{
    config([
        'social_authorization.network_to_provider.instagram' => 'instagram',
        'social_authorization.providers.instagram' => [
            'driver' => 'instagram_login',
            'enabled' => true,
            'client_id' => 'instagram-app-id',
            'client_secret' => 'instagram-app-secret',
            'redirect_uri' => 'https://cr8or.test/social/authorization/callback/instagram',
            'authorization_url' => 'https://www.instagram.com/oauth/authorize',
            'token_url' => 'https://api.instagram.com/oauth/access_token',
            'graph_api_version' => 'v26.0',
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            'destination_networks' => ['instagram'],
            'refresh' => ['strategy' => 'instagram_long_lived', 'url' => 'https://graph.instagram.com/refresh_access_token'],
            'identity' => ['url' => 'https://graph.instagram.com/v26.0/me?fields=user_id,username,account_type'],
            'revoke' => ['url' => null],
        ],
    ]);
    Gate::before(fn (): bool => true);
}

it('uses native Instagram Login with only the current Instagram business scopes', function (): void {
    configureInstagramLogin();
    $actor = User::factory()->create();
    $organization = Organization::factory()->create();

    $url = app(SocialAuthorizationService::class)->start($actor, (int) $organization->id, 'instagram');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect(parse_url($url, PHP_URL_HOST))->toBe('www.instagram.com')
        ->and(parse_url($url, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and($query['scope'] ?? null)->toBe('instagram_business_basic,instagram_business_content_publish')
        ->and($query['enable_fb_login'] ?? null)->toBe('0')
        ->and($query['redirect_uri'] ?? null)->toBe('https://cr8or.test/social/authorization/callback/instagram')
        ->and(config('social_authorization.network_to_provider.facebook'))->toBe('meta')
        ->and(config('social_authorization.providers.meta.scopes'))->not->toContain('instagram_basic', 'instagram_content_publish');
});

it('exchanges the Instagram code for a long-lived token and discovers the professional account without a Page', function (): void {
    configureInstagramLogin();
    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-instagram-token',
            'user_id' => 17841400000000000,
            'permissions' => ['instagram_business_basic', 'instagram_business_content_publish'],
        ]),
        'https://graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-lived-instagram-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
        'https://graph.instagram.com/v26.0/me?fields=user_id,username,account_type' => Http::response([
            'user_id' => '17841400000000000',
            'username' => 'cr8or.test',
            'account_type' => 'BUSINESS',
        ]),
    ]);

    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $service = app(SocialAuthorizationService::class);
    $url = $service->start($actor, (int) $organization->id, 'instagram');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $attempt = $service->complete($actor, 'instagram', $query['state'], 'authorization-code');

    expect($attempt->status)->toBe(SocialAuthorizationAttempt::STATUS_COMPLETED)
        ->and($attempt->discovered_destinations)->toBe([[
            'provider' => 'instagram',
            'external_id' => '17841400000000000',
            'name' => 'cr8or.test',
            'url' => 'https://www.instagram.com/cr8or.test/',
        ]]);

    $identity = SocialIdentity::query()->where('organization_id', $organization->id)->sole();
    $connection = IntegrationConnection::query()->where('social_identity_id', $identity->id)->sole();
    $credential = app(SocialCredentialStore::class)->forConnection($connection);

    expect($identity->provider)->toBe('instagram')
        ->and($connection->provider)->toBe('instagram')
        ->and($credential->tokenSet()->accessToken)->toBe('long-lived-instagram-token')
        ->and($credential->tokenSet()->scopes)->toBe(['instagram_business_basic', 'instagram_business_content_publish'])
        ->and($credential->tokenSet()->accessTokenExpiresAt)->not->toBeNull()
        ->and($credential->getRawOriginal('encrypted_payload'))->not->toContain('long-lived-instagram-token');

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request->url() === 'https://api.instagram.com/oauth/access_token'
        && str_contains((string) $request->header('Content-Type')[0], 'multipart/form-data')
        && str_contains($request->body(), 'grant_type')
        && str_contains($request->body(), 'authorization_code')
        && str_contains($request->body(), 'instagram-app-secret')
        && str_contains($request->body(), 'authorization-code'));
    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.instagram.com/access_token')
        && $request['grant_type'] === 'ig_exchange_token'
        && $request['access_token'] === 'short-lived-instagram-token');
});

it('refreshes an unexpired long-lived Instagram token without using Facebook endpoints', function (): void {
    configureInstagramLogin();
    Http::fake([
        'https://graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'refreshed-instagram-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
    ]);

    $provider = app(App\Services\Social\SocialAuthorizationProviderRegistry::class)->forProvider('instagram');
    $current = new SocialTokenSet(
        accessToken: 'old-instagram-token',
        refreshToken: null,
        accessTokenExpiresAt: CarbonImmutable::now()->addDay(),
        refreshTokenExpiresAt: null,
        scopes: ['instagram_business_basic', 'instagram_business_content_publish'],
    );
    $refreshed = $provider->refresh($current);

    expect($refreshed->accessToken)->toBe('refreshed-instagram-token')
        ->and($refreshed->scopes)->toBe($current->scopes)
        ->and($refreshed->accessTokenExpiresAt)->toBeGreaterThan(CarbonImmutable::now());

    Http::assertSent(fn ($request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.instagram.com/refresh_access_token')
        && $request['grant_type'] === 'ig_refresh_token'
        && $request['access_token'] === 'old-instagram-token');
});

it('migrates an existing Instagram destination from the legacy Meta identity only when the account ID matches', function (): void {
    configureInstagramLogin();
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'type' => 'social']);
    $legacyIdentity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'meta',
        'external_id' => 'legacy-meta-user-id',
        'name' => 'Legacy Meta User',
    ]);
    $instagramUserId = '17841400000000000';
    $accountId = DB::table('social_accounts')->insertGetId([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $legacyIdentity->id,
        'provider' => 'instagram',
        'name' => 'cr8or.test',
        'external_id' => $instagramUserId,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $instagramIdentity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'instagram',
        'external_id' => $instagramUserId,
        'name' => 'cr8or.test',
    ]);

    $account = App\Models\SocialAccount::query()->findOrFail($accountId);
    $account->social_identity_id = $instagramIdentity->id;
    $account->save();

    expect($account->fresh()->social_identity_id)->toBe($instagramIdentity->id)
        ->and($legacyIdentity->fresh()->provider)->toBe('meta');

    $mismatchedIdentity = SocialIdentity::query()->create([
        'organization_id' => $organization->id,
        'provider' => 'instagram',
        'external_id' => '17841400000000099',
        'name' => 'another.account',
    ]);
    $account->social_identity_id = $mismatchedIdentity->id;

    expect(fn () => $account->save())->toThrow(LogicException::class, 'cannot be reassigned');
});

it('fails closed when Instagram does not grant all requested permissions', function (): void {
    configureInstagramLogin();
    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-instagram-token',
            'user_id' => '17841400000000000',
            'permissions' => ['instagram_business_basic'],
        ]),
    ]);

    $provider = app(App\Services\Social\SocialAuthorizationProviderRegistry::class)->forProvider('instagram');

    expect(fn () => $provider->exchangeAuthorizationCode(
        'authorization-code',
        'https://cr8or.test/social/authorization/callback/instagram',
        null,
    ))->toThrow(App\Exceptions\SocialAuthorizationException::class, 'did not grant every required permission');

    Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://graph.instagram.com/access_token'));
});
