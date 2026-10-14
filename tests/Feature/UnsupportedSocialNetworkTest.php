<?php

use App\Exceptions\SocialAuthorizationException;
use App\Services\Social\SocialAuthorizationProviderRegistry;

it('does not expose removed networks in authorization or publishing configuration', function (): void {
    expect(array_keys(config('publishing.adapters')))->toBe([
        'linkedin', 'facebook', 'instagram', 'tiktok', 'youtube', 'threads',
    ])
        ->and(config('social_authorization.network_to_provider'))->not->toHaveKeys(['bsky', 'x'])
        ->and(config('social_authorization.providers'))->not->toHaveKeys(['bluesky', 'x'])
        ->and(config('social_authorization.credential_resolvers'))->not->toHaveKeys(['bluesky', 'x']);
});

it('rejects removed networks as unsupported authorization destinations', function (string $network): void {
    expect(fn () => app(SocialAuthorizationProviderRegistry::class)->providerForNetwork($network))
        ->toThrow(SocialAuthorizationException::class);
})->with(['bsky', 'x']);

it('does not expose the removed AT Protocol client metadata endpoint', function (): void {
    $this->get('/oauth-client-metadata.json')->assertNotFound();
});
