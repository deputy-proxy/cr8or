<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\ConnectSocialAccountTool;
use App\Models\Channel;
use App\Models\Enterprise;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DomainResourceService;
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

it('trims provider and display name without rewriting provider identifier casing', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();

    $account = app(DomainResourceService::class)->connectSocialAccount($actor, $enterprise, [
        'channel_id' => $channel->getKey(),
        'provider' => '  Instagram  ',
        'name' => '  Brand Account  ',
        'external_id' => 'Account-AbC-42',
    ]);

    expect($account->provider)->toBe('Instagram')
        ->and($account->name)->toBe('Brand Account')
        ->and($account->external_id)->toBe('Account-AbC-42');
});

it('rejects whitespace-only fields and padded external identifiers with field errors', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();
    $service = app(DomainResourceService::class);

    foreach ([
        ['provider' => '   ', 'name' => 'Account', 'external_id' => 'id-1', 'field' => 'provider'],
        ['provider' => 'Instagram', 'name' => " \t ", 'external_id' => 'id-2', 'field' => 'name'],
        ['provider' => 'Instagram', 'name' => 'Account', 'external_id' => " \t ", 'field' => 'external_id'],
        ['provider' => 'Instagram', 'name' => 'Account', 'external_id' => ' id-3 ', 'field' => 'external_id'],
    ] as $case) {
        try {
            $service->connectSocialAccount($actor, $enterprise, [
                'channel_id' => $channel->getKey(),
                'provider' => $case['provider'],
                'name' => $case['name'],
                'external_id' => $case['external_id'],
            ]);

            test()->fail("Expected validation error for [{$case['field']}].");
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey($case['field']);
        }
    }

    expect(SocialAccount::query()->count())->toBe(0);
});

it('normalizes display names on update without rewriting the external identity', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = app(DomainResourceService::class)->connectSocialAccount($actor, $enterprise, [
        'channel_id' => $channel->getKey(),
        'provider' => 'Instagram',
        'name' => 'Original Name',
        'external_id' => 'Immutable-Case-ID',
    ]);

    $updated = app(DomainResourceService::class)->updateSocialAccount($actor, $account, [
        'name' => '  Updated Name  ',
    ]);

    expect($updated->name)->toBe('Updated Name')
        ->and($updated->external_id)->toBe('Immutable-Case-ID')
        ->and($updated->provider)->toBe('Instagram');
});

it('applies the same normalization contract to direct model writes and rejects duplicate provider identities', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();

    $account = SocialAccount::query()->create([
        'enterprise_id' => $enterprise->getKey(),
        'channel_id' => $channel->getKey(),
        'provider' => '  FaceBook ',
        'name' => '  Primary Account ',
        'external_id' => 'CaseSensitive-42',
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);

    expect($account->provider)->toBe('FaceBook')
        ->and($account->name)->toBe('Primary Account')
        ->and($account->external_id)->toBe('CaseSensitive-42');

    try {
        SocialAccount::query()->create([
            'enterprise_id' => $enterprise->getKey(),
            'channel_id' => $channel->getKey(),
            'provider' => 'FaceBook',
            'name' => 'Duplicate Account',
            'external_id' => 'CaseSensitive-42',
            'status' => SocialAccount::STATUS_ACTIVE,
        ]);

        test()->fail('Expected duplicate provider identity validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('external_id');
    }
});

it('normalizes social account creation through the MCP tool', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ConnectSocialAccountTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'channel_id' => $channel->getKey(),
            'provider' => '  LinkedIn  ',
            'name' => '  Company Page  ',
            'external_id' => 'LinkedIn-Account-ABC',
        ])
        ->assertHasNoErrors();

    $account = SocialAccount::query()->sole();

    expect($account->provider)->toBe('LinkedIn')
        ->and($account->name)->toBe('Company Page')
        ->and($account->external_id)->toBe('LinkedIn-Account-ABC');

    Cr8orServer::actingAs($actor, 'api')
        ->tool(ConnectSocialAccountTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'channel_id' => $channel->getKey(),
            'provider' => 'LinkedIn',
            'name' => 'Invalid Padded Identity',
            'external_id' => ' LinkedIn-Account-XYZ ',
        ])
        ->assertHasErrors();

    expect(SocialAccount::query()->count())->toBe(1);
});

it('rejects cross-enterprise channel changes before persistence and preserves historical external identifiers', function (): void {
    [$actor, $enterprise, $channel] = socialAccountNormalizationContext();
    $account = app(DomainResourceService::class)->connectSocialAccount($actor, $enterprise, [
        'channel_id' => $channel->getKey(),
        'provider' => 'Instagram',
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
        'status' => SocialAccount::STATUS_ACTIVE,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $legacy = SocialAccount::query()->where('provider', 'legacy-provider')->firstOrFail();
    $legacy->name = '  Updated Display Name  ';
    $legacy->save();

    expect($legacy->refresh()->name)->toBe('Updated Display Name')
        ->and($legacy->external_id)->toBe('legacy-id-with-padding ');

    $account->channel_id = $foreignChannel->getKey();
    expect(fn () => $account->save())->toThrow(LogicException::class);
    expect($account->refresh()->channel_id)->toBe($channel->getKey());
});
