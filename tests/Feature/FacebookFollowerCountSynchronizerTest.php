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
use App\Services\Social\FacebookFollowerCountSynchronizer;
use App\Services\Social\SocialCredentialStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function facebookFollowerSyncContext(
    ?int $existingCount = null,
    array $scopes = ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
    array $destinationTokens = ['facebook:123456' => ['access_token' => 'page-access-token', 'expires_at' => null]],
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
        'provider' => 'facebook',
        'name' => 'Afacere Online',
        'external_id' => '123456',
        'status' => SocialAccount::STATUS_ACTIVE,
        'follower_count' => $existingCount,
    ]);

    $credentials = Mockery::mock(CredentialResolver::class);
    $credentials->shouldReceive('resolveAccessToken')
        ->with(Mockery::on(fn ($candidate): bool => $candidate instanceof IntegrationConnection && $candidate->is($connection)), 'facebook')
        ->andReturn('parent-user-token');
    app()->instance(CredentialResolver::class, $credentials);

    return [$account, $connection, app(FacebookFollowerCountSynchronizer::class)];
}

it('fetches and persists the real Facebook Page follower count using the stored Page token', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'followers_count' => 1842,
        ]),
    ]);

    expect($synchronizer->synchronize($account))->toBe(1842)
        ->and($account->fresh()->follower_count)->toBe(1842);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), 'https://graph.facebook.com/v26.0/123456')
        && $request->hasHeader('Authorization', 'Bearer page-access-token'));
});

it('persists zero as a real follower count', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'followers_count' => 0,
        ]),
    ]);

    expect($synchronizer->synchronize($account))->toBe(0)
        ->and($account->fresh()->follower_count)->toBe(0);
});

it('preserves the previous follower count when Meta returns an error', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext(1842);

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'error' => ['message' => 'Permission denied', 'type' => 'OAuthException', 'code' => 10],
        ], 400),
    ]);

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(RuntimeException::class, 'Meta rejected the Facebook follower metrics request.')
        ->and($account->fresh()->follower_count)->toBe(1842);
});

it('rejects malformed responses without overwriting the previous count', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext(1842);

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'followers_count' => 'unknown',
        ]),
    ]);

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(RuntimeException::class, 'Meta returned an invalid Facebook follower metrics response.')
        ->and($account->fresh()->follower_count)->toBe(1842);
});

it('rejects non-Facebook destinations before making provider requests', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext();
    $account->provider = 'linkedin';
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(RuntimeException::class, 'Only active Facebook Page destinations');
});

it('rejects a Facebook destination without an operational Meta connection', function () {
    [$account, $connection, $synchronizer] = facebookFollowerSyncContext();
    $connection->transitionTo(IntegrationConnection::STATUS_DISABLED)->save();
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'matching operational Meta connection');
});

it('rejects missing Page tokens before requesting follower metrics', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext(destinationTokens: []);
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'No Page access token is stored');
});

it('rejects expired Page tokens before requesting follower metrics', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext(destinationTokens: [
        'facebook:123456' => ['access_token' => 'page-access-token', 'expires_at' => now()->subMinute()->toIso8601String()],
    ]);
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'Page access token has expired');
});

it('rejects missing pages_read_engagement permission without requesting follower metrics', function () {
    [$account, , $synchronizer] = facebookFollowerSyncContext(scopes: ['pages_show_list', 'pages_manage_posts']);
    Http::preventStrayRequests();

    expect(fn () => $synchronizer->synchronize($account))
        ->toThrow(SocialAuthorizationException::class, 'lacks pages_read_engagement');
});

it('refreshes connected Facebook Page counts through the scheduled command', function () {
    [$account] = facebookFollowerSyncContext();

    Http::fake([
        'https://graph.facebook.com/v26.0/123456*' => Http::response([
            'id' => '123456',
            'followers_count' => 1842,
        ]),
    ]);

    $this->artisan('social-accounts:sync-facebook-follower-counts')
        ->expectsOutput('Facebook follower counts refreshed: 1; failed: 0.')
        ->assertExitCode(0);

    expect($account->fresh()->follower_count)->toBe(1842);
});
