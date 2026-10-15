<?php

use App\Contracts\CredentialResolver;
use App\Data\Social\SocialTokenSet;
use App\Exceptions\SocialAuthorizationException;
use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\SocialIdentity;
use App\Services\Social\InstagramFollowerCountSynchronizer;
use App\Services\Social\SocialCredentialStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function instagramFollowerSyncContext(
    ?int $existingCount = null,
    array $scopes = ['pages_show_list', 'pages_read_engagement', 'instagram_basic', 'instagram_content_publish'],
    array $destinationTokens = ['instagram:123456' => ['access_token' => 'instagram-access-token', 'expires_at' => null]],
): array {
    config(['social_authorization.providers.meta.graph_api_version' => 'v26.0']);

    $enterprise = Enterprise::factory()->create();
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id]);
    $identity = SocialIdentity::query()->create([
        'organization_id' => $enterprise->organization_id,
        'provider' => 'meta',
        'external_id' => 'meta-user-123',
        'name' => 'Meta User',
    ]);

    $credentialReference = app(SocialCredentialStore::class)->store(
        Organization::query()->findOrFail($enterprise->organization_id),
        'meta',
        new SocialTokenSet(
            accessToken: 'parent-user-token',
            refreshToken: null,
            accessTokenExpiresAt: null,
            refreshTokenExpiresAt: null,
            scopes: $scopes,
            destinationTokens: $destinationTokens,
        ),
    );

    $connection = IntegrationConnection::query()->create([
        'organization_id' => $enterprise->organization_id,
        'social_identity_id' => $identity->id,
        'provider' => 'meta',
        'external_account_id' => $identity->external_id,
        'credential_reference' => $credentialReference,
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);

    $account = SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $identity->id,
        'provider' => 'instagram',
        'name' => 'Afacere Online',
        'external_id' => '123456',
        'status' => SocialAccount::STATUS_ACTIVE,
        'follower_count' => $existingCount,
    ]);

    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'instagram')
        ->andReturn('parent-user-token');
    app()->instance(CredentialResolver::class, $credentials);

    return [$account, $connection, app(InstagramFollowerCountSynchronizer::class)];
}

it('fetches and persists the real Instagram professional account follower count using the stored Instagram destination token', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'username' => 'afacereonline',
            'followers_count' => 1842,
        ]),
    ]);

    expect($synchronizer->synchronize($account))->toBe(1842)
        ->and($account->fresh()->follower_count)->toBe(1842)
        ->and($account->fresh()->profile_url)->toBe('https://www.instagram.com/afacereonline/');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/123456')
        && $request->data()['fields'] === 'id,username,followers_count'
        && $request->hasHeader('Authorization', 'Bearer instagram-access-token'));
});

it('preserves an existing Instagram profile URL when Meta omits the username', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext();
    $account->profile_url = 'https://www.instagram.com/existing-page/';
    $account->save();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'followers_count' => 1842,
        ]),
    ]);

    $synchronizer->synchronize($account);

    expect($account->fresh()->profile_url)->toBe('https://www.instagram.com/existing-page/');
});

it('does not persist an invalid username returned by Meta', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'username' => 'https://evil.example/impersonation',
            'followers_count' => 1842,
        ]),
    ]);

    $synchronizer->synchronize($account);

    expect($account->fresh()->follower_count)->toBe(1842)
        ->and($account->fresh()->profile_url)->toBeNull();
});

it('persists zero as a real follower count', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'username' => 'afacereonline',
            'followers_count' => 0,
        ]),
    ]);

    expect($synchronizer->synchronize($account))->toBe(0)
        ->and($account->fresh()->follower_count)->toBe(0);
});

it('preserves the previous follower count when Meta returns an error', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext(1842);

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'error' => ['message' => 'Permission denied', 'type' => 'OAuthException', 'code' => 10],
        ], 400),
    ]);

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(RuntimeException::class, 'Meta rejected the Instagram follower metrics request.')
        ->and($account->fresh()->follower_count)->toBe(1842);
});

it('rejects malformed responses without overwriting the previous count', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext(1842);

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'username' => 'afacereonline',
            'followers_count' => 'unknown',
        ]),
    ]);

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(RuntimeException::class, 'Meta returned an invalid Instagram follower metrics response.')
        ->and($account->fresh()->follower_count)->toBe(1842);
});

it('rejects non-Instagram destinations before making provider requests', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext();
    $account->provider = 'linkedin';
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(RuntimeException::class, 'Only active Instagram professional account destinations');
});

it('rejects a Instagram destination without an operational Meta connection', function () {
    [$account, $connection, $synchronizer] = instagramFollowerSyncContext();
    $connection->transitionTo(IntegrationConnection::STATUS_DISABLED)->save();
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'matching operational Meta connection');
});

it('rejects missing Instagram destination tokens before requesting follower metrics', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext(destinationTokens: []);
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'No destination token is stored');
});

it('rejects expired Instagram destination tokens before requesting follower metrics', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext(destinationTokens: [
        'instagram:123456' => ['access_token' => 'instagram-access-token', 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'Instagram destination token has expired');
});

it('rejects missing instagram_basic permission without requesting follower metrics', function () {
    [$account, , $synchronizer] = instagramFollowerSyncContext(scopes: ['pages_show_list', 'instagram_content_publish']);
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'lacks instagram_basic');
});

it('refreshes connected Instagram professional account counts through the scheduled command', function () {
    [$account] = instagramFollowerSyncContext();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'username' => 'afacereonline',
            'followers_count' => 1842,
        ]),
    ]);

    $this->artisan('social-accounts:sync-instagram-follower-counts')
        ->expectsOutput('Instagram follower counts refreshed: 1; failed: 0.')
        ->assertExitCode(0);

    expect($account->fresh()->follower_count)->toBe(1842);
});