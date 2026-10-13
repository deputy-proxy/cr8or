<?php

use Illuminate\Support\Facades\Artisan;

it('keeps every native publishing adapter disabled by default', function () {
    foreach (config('publishing.adapters') as $adapter) {
        expect($adapter['enabled'])->toBeFalse();
    }

    expect(Artisan::call('social:publishing-readiness'))->toBe(0)
        ->and(Artisan::output())->toContain('No native social publishing adapters are enabled');
});

it('fails preflight for an enabled network with invalid callback configuration without printing credentials', function () {
    config([
        'publishing.adapters.x.enabled' => true,
        'social_authorization.providers.x.enabled' => true,
        'social_authorization.providers.x.client_id' => 'test-client-id',
        'social_authorization.providers.x.client_secret' => 'never-print-this-secret',
        'social_authorization.providers.x.redirect_uri' => 'https://attacker.example/callback/not-x',
    ]);

    expect(Artisan::call('social:publishing-readiness'))->toBe(1);

    $output = Artisan::output();
    expect($output)
        ->toContain('x: The OAuth callback URL is invalid')
        ->not->toContain('never-print-this-secret')
        ->not->toContain('test-client-id');
});

it('fails preflight when the enabled adapter has no configured OAuth provider', function () {
    config([
        'publishing.adapters.x.enabled' => true,
        'social_authorization.providers.x.enabled' => false,
        'social_authorization.providers.x.client_id' => null,
        'social_authorization.providers.x.client_secret' => null,
    ]);

    expect(Artisan::call('social:publishing-readiness'))->toBe(1);

    $output = Artisan::output();
    expect($output)
        ->toContain('x: The mapped social authorization provider is disabled or missing required credentials.')
        ->toContain('x: The mapped social authorization provider is missing its client identifier.')
        ->toContain('x: The mapped social authorization provider is missing its client secret.');
});

it('passes preflight for a fully configured disabled-by-default network when explicitly enabled', function () {
    config([
        'publishing.adapters.x.enabled' => true,
        'social_authorization.providers.x.enabled' => true,
        'social_authorization.providers.x.client_id' => 'test-client-id',
        'social_authorization.providers.x.client_secret' => 'test-client-secret',
        'social_authorization.providers.x.redirect_uri' => 'http://localhost/social/authorization/callback/x',
    ]);

    expect(Artisan::call('social:publishing-readiness'))->toBe(0)
        ->and(Artisan::output())->toContain('x: configuration preflight passed')
        ->and(Artisan::output())->not->toContain('test-client-secret');
});
