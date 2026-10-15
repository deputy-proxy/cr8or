<?php

use App\Data\Social\SocialTokenSet;
use App\Models\Organization;
use App\Models\SocialAuthorizationAttempt;
use App\Models\User;
use App\Services\Social\InstagramLoginAuthorizationProvider;
use App\Services\Social\SocialAuthorizationProviderRegistry;
use App\Services\Social\SocialAuthorizationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;

function instagramLoginProvider(): InstagramLoginAuthorizationProvider
{
    return new InstagramLoginAuthorizationProvider([
        'client_id' => 'instagram-app-id',
        'client_secret' => 'instagram-app-secret',
        'redirect_uri' => 'https://cr8or.test/social/authorization/callback/instagram',
        'graph_api_version' => 'v26.0',
    ]);
}

it('uses the dedicated Instagram Login endpoint and only current business scopes', function () {
    config([
        'social_authorization.network_to_provider.instagram' => 'instagram',
        'social_authorization.providers.instagram' => [
            'driver' => 'instagram_login',
            'enabled' => true,
            'client_id' => 'instagram-app-id',
            'client_secret' => 'instagram-app-secret',
            'redirect_uri' => 'https://cr8or.test/social/authorization/callback/instagram',
            'graph_api_version' => 'v26.0',
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            'destination_networks' => ['instagram'],
        ],
    ]);

    expect(app(SocialAuthorizationProviderRegistry::class)->providerForNetwork('instagram'))->toBe('instagram');

    $provider = instagramLoginProvider();
    $initiation = $provider->initiate(
        'cryptographically-random-state',
        'https://cr8or.test/social/authorization/callback/instagram',
        ['instagram_business_basic', 'instagram_business_content_publish'],
        'unused-verifier',
        null,
    );
    parse_str((string) parse_url($initiation->url, PHP_URL_QUERY), $query);

    expect(parse_url($initiation->url, PHP_URL_HOST))->toBe('www.instagram.com')
        ->and(parse_url($initiation->url, PHP_URL_PATH))->toBe('/oauth/authorize')
        ->and($query['scope'])->toBe('instagram_business_basic,instagram_business_content_publish')
        ->and($query['state'])->toBe('cryptographically-random-state')
        ->and($query['enable_fb_login'])->toBe('0')
        ->and($query['scope'])->not->toContain('instagram_basic')
        ->and($query['scope'])->not->toContain('instagram_content_publish');
});

it('routes the authorization service through Instagram Login without legacy scopes', function () {
    config([
        'social_authorization.network_to_provider.instagram' => 'instagram',
        'social_authorization.providers.instagram' => [
            'driver' => 'instagram_login',
            'enabled' => true,
            'client_id' => 'instagram-app-id',
            'client_secret' => 'instagram-app-secret',
            'redirect_uri' => 'https://cr8or.test/social/authorization/callback/instagram',
            'graph_api_version' => 'v26.0',
            'scopes' => ['instagram_business_basic', 'instagram_business_content_publish'],
            'destination_networks' => ['instagram'],
        ],
    ]);
    Gate::before(fn (): bool => true);

    $actor = User::factory()->create();
    $organization = Organization::factory()->create();
    $url = app(SocialAuthorizationService::class)->start($actor, (int) $organization->id, 'instagram');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $attempt = SocialAuthorizationAttempt::query()->sole();

    expect(parse_url($url, PHP_URL_HOST))->toBe('www.instagram.com')
        ->and($query['scope'])->toBe('instagram_business_basic,instagram_business_content_publish')
        ->and($attempt->provider)->toBe('instagram')
        ->and($attempt->requested_scopes)->toBe(['instagram_business_basic', 'instagram_business_content_publish']);
});

it('exchanges Instagram authorization codes for encrypted-ready long-lived professional account tokens', function () {
    Http::fake([
        'https://api.instagram.com/oauth/access_token' => Http::response([
            'access_token' => 'short-lived-instagram-token',
            'user_id' => 123456789,
        ]),
        'https://graph.instagram.com/access_token*' => Http::response([
            'access_token' => 'long-lived-instagram-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
        'https://graph.instagram.com/v26.0/me*' => Http::response([
            'user_id' => '123456789',
            'username' => 'cr8or_test',
            'account_type' => 'BUSINESS',
        ]),
    ]);

    $provider = instagramLoginProvider();
    $initiation = $provider->initiate(
        'state',
        'https://cr8or.test/social/authorization/callback/instagram',
        ['instagram_business_basic', 'instagram_business_content_publish'],
        'unused-verifier',
        null,
    );
    $tokens = $provider->exchangeWithContext(
        'authorization-code',
        'https://cr8or.test/social/authorization/callback/instagram',
        'unused-verifier',
        $initiation->context,
        [],
    );

    expect($tokens->accessToken)->toBe('long-lived-instagram-token')
        ->and($tokens->scopes)->toBe(['instagram_business_basic', 'instagram_business_content_publish'])
        ->and($tokens->accessTokenExpiresAt)->toBeInstanceOf(CarbonImmutable::class)
        ->and($tokens->destinationTokens['instagram:123456789']['access_token'])->toBe('long-lived-instagram-token')
        ->and($tokens->providerContext['username'])->toBe('cr8or_test');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.instagram.com/oauth/access_token'
        && $request->method() === 'POST'
        && $request['grant_type'] === 'authorization_code'
        && $request['client_secret'] === 'instagram-app-secret');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://graph.instagram.com/access_token')
        && $request['grant_type'] === 'ig_exchange_token');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'facebook.com'));
});

it('refreshes the long-lived Instagram token and its account-scoped credential together', function () {
    Http::fake([
        'https://graph.instagram.com/refresh_access_token*' => Http::response([
            'access_token' => 'refreshed-instagram-token',
            'token_type' => 'bearer',
            'expires_in' => 5184000,
        ]),
    ]);

    $provider = instagramLoginProvider();
    $current = new SocialTokenSet(
        'expiring-instagram-token',
        null,
        CarbonImmutable::now()->addMinutes(2),
        null,
        ['instagram_business_basic', 'instagram_business_content_publish'],
        'Bearer',
        ['instagram:123456789' => ['access_token' => 'expiring-instagram-token', 'expires_at' => CarbonImmutable::now()->addMinutes(2)->toIso8601String()]],
        ['username' => 'cr8or_test'],
    );

    $refreshed = $provider->refresh($current);

    expect($refreshed->accessToken)->toBe('refreshed-instagram-token')
        ->and($refreshed->destinationTokens['instagram:123456789']['access_token'])->toBe('refreshed-instagram-token')
        ->and($refreshed->scopes)->toBe($current->scopes);
});