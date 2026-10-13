<?php

use App\Filament\Resources\SocialAccounts\SocialAccountResource;
use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Operations\SocialAccountDisable;
use App\Operations\SocialAccountUpdate;
use App\Services\DomainResourceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function socialAccountNormalizationContext(): array
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'Instagram']);

    return [$actor, $enterprise, $channel];
}

function createNormalizationAccount(Enterprise $enterprise, Channel $channel, array $attributes = []): SocialAccount
{
    return SocialAccount::query()->create(array_merge([
        'enterprise_id' => $enterprise->getKey(),
        'channel_id' => $channel->getKey(),
        'provider' => 'legacy-provider',
        'name' => 'Brand Account',
        'external_id' => 'Account-AbC-42',
        'status' => SocialAccount::STATUS_DISABLED,
    ], $attributes));
}

it('normalizes provider and display name without rewriting external identifier casing', function (): void {
    [, $enterprise, $channel] = socialAccountNormalizationContext();

    $account = createNormalizationAccount($enterprise, $channel, [
        'provider' => '  LegacyProvider  ',
        'name' => '  Brand Account  ',
        'external_id' => 'Account-AbC-42',
    ]);

    expect($account->provider)->toBe('LegacyProvider')
        ->and($account->name)->toBe('Brand Account')
        ->and($account->external_id)->toBe('Account-AbC-42');
});

it('rejects whitespace-only fields and padded external identifiers with field errors', function (): void {
    [, $enterprise, $channel] = socialAccountNormalizationContext();

    foreach ([
        ['provider' => '   ', 'name' => 'Account', 'external_id' => 'id-1', 'field' => 'provider'],
        ['provider' => 'LegacyProvider', 'name' => " \t ", 'external_id' => 'id-2', 'field' => 'name'],
        ['provider' => 'LegacyProvider', 'name' => 'Account', 'external_id' => " \t ", 'field' => 'external_id'],
        ['provider' => 'LegacyProvider', 'name' => 'Account', 'external_id' => ' id-3 ', 'field' => 'external_id'],
    ] as $case) {
        try {
            createNormalizationAccount($enterprise, $channel, [
                'provider' => $case['provider'],
                'name' => $case['name'],
                'external_id' => $case['external_id'],
            ]);

            throw new LogicException("Expected validation error for [{$case['field']}].");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($case['field']);
        }
    }

    expect(SocialAccount::query()->count())->toBe(0);
});

it('normalizes display names on update without rewriting the external identity', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = createNormalizationAccount($enterprise, $channel, [
        'name' => 'Original Name',
        'external_id' => 'Immutable-Case-ID',
    ]);

    $updated = app(DomainResourceService::class)->updateSocialAccount($actor, $account, [
        'name' => '  Updated Name  ',
    ]);

    expect($updated->name)->toBe('Updated Name')
        ->and($updated->external_id)->toBe('Immutable-Case-ID')
        ->and($updated->provider)->toBe('legacy-provider');
});

it('applies normalization to direct model writes and rejects duplicate provider identities', function (): void {
    [, $enterprise, $channel] = socialAccountNormalizationContext();

    $account = createNormalizationAccount($enterprise, $channel, [
        'provider' => '  FaceBook ',
        'name' => '  Primary Account ',
        'external_id' => 'CaseSensitive-42',
    ]);

    expect($account->provider)->toBe('FaceBook')
        ->and($account->name)->toBe('Primary Account')
        ->and($account->external_id)->toBe('CaseSensitive-42');

    try {
        createNormalizationAccount($enterprise, $channel, [
            'provider' => 'FaceBook',
            'name' => 'Duplicate Account',
            'external_id' => 'CaseSensitive-42',
        ]);

        throw new LogicException('Expected duplicate provider identity validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('external_id');
    }
});

it('routes social destination update and disable through explicit Operations', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = createNormalizationAccount($enterprise, $channel, [
        'name' => 'Original Name',
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);

    $updated = app(SocialAccountUpdate::class)->execute($actor, [
        'social_account_id' => $account->getKey(),
        'name' => '  Updated by Operation  ',
    ]);
    $disabled = app(SocialAccountDisable::class)->execute($actor, [
        'social_account_id' => $updated->getKey(),
    ]);

    expect($updated->name)->toBe('Updated by Operation')
        ->and($disabled->status)->toBe(SocialAccount::STATUS_DISABLED);
});

it('denies direct social destination Operations outside the authorized enterprise', function (): void {
    [, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = createNormalizationAccount($enterprise, $channel, ['status' => SocialAccount::STATUS_ACTIVE]);
    $otherOrganization = Organization::factory()->create();
    $otherActor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $otherActor->getKey(),
        'organization_id' => $otherOrganization->getKey(),
    ]);

    expect(fn () => app(SocialAccountUpdate::class)->execute($otherActor, [
        'social_account_id' => $account->getKey(),
        'name' => 'Unauthorized',
    ]))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SocialAccountDisable::class)->execute($otherActor, [
            'social_account_id' => $account->getKey(),
        ]))->toThrow(AuthorizationException::class);
});

it('does not expose generic social-account creation outside the OAuth destination lifecycle', function (): void {
    expect(SocialAccountResource::canCreate())->toBeFalse()
        ->and(SocialAccountResource::getPages())->not->toHaveKey('create')
        ->and(method_exists(DomainResourceService::class, 'connectSocialAccount'))->toBeFalse();
});

it('rejects cross-enterprise channel changes before persistence and preserves historical external identifiers', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = createNormalizationAccount($enterprise, $channel, [
        'name' => 'Original Name',
        'external_id' => 'legacy-external-id',
    ]);

    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    $foreignChannel = Channel::factory()->create(['enterprise_id' => $foreignEnterprise->getKey()]);

    expect(fn () => app(DomainResourceService::class)->updateSocialAccount($actor, $account, [
        'name' => 'Must Not Persist',
        'channel_id' => $foreignChannel->getKey(),
    ]))->toThrow(LogicException::class);

    expect($account->refresh()->enterprise_id)->toBe($enterprise->getKey())
        ->and($account->channel_id)->toBe($channel->getKey())
        ->and($account->name)->toBe('Original Name');

    DB::table('social_accounts')->insert([
        'enterprise_id' => $enterprise->getKey(),
        'channel_id' => $channel->getKey(),
        'provider' => 'legacy-provider',
        'name' => 'Legacy Account',
        'external_id' => 'legacy-id-with-padding ',
        'status' => SocialAccount::STATUS_DISABLED,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacy = SocialAccount::query()->where('name', 'Legacy Account')->firstOrFail();
    $legacy->name = '  Updated Display Name  ';
    $legacy->save();

    expect($legacy->refresh()->name)->toBe('Updated Display Name')
        ->and($legacy->external_id)->toBe('legacy-id-with-padding ');
});

it('allows enterprise owners to delete unused social destinations through the domain service', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = createNormalizationAccount($enterprise, $channel);

    app(DomainResourceService::class)->deleteSocialAccount($actor, $account);

    expect(SocialAccount::query()->whereKey($account->getKey())->exists())->toBeFalse();
});

it('denies social destination deletion outside the owning enterprise', function (): void {
    [, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = createNormalizationAccount($enterprise, $channel);
    $otherOrganization = Organization::factory()->create();
    $otherActor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $otherActor->getKey(),
        'organization_id' => $otherOrganization->getKey(),
    ]);

    expect(fn () => app(DomainResourceService::class)->deleteSocialAccount($otherActor, $account))
        ->toThrow(AuthorizationException::class)
        ->and(SocialAccount::query()->whereKey($account->getKey())->exists())->toBeTrue();
});
