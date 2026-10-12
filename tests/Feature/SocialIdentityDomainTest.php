<?php

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\IntegrationConnection;
use App\Models\MarketingStrategy;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\SocialIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

function socialIdentityDomainContext(): array
{
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'type' => 'linkedin']);
    $identity = SocialIdentity::factory()->create([
        'organization_id' => $organization->id,
        'provider' => 'linkedin',
        'external_id' => 'member-opaque-01',
    ]);

    return [$organization, $enterprise, $channel, $identity];
}

function socialIdentityDestination(Enterprise $enterprise, Channel $channel, SocialIdentity $identity, string $externalId, string $name = 'Company Page'): SocialAccount
{
    return SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $identity->id,
        'provider' => $channel->type,
        'external_id' => $externalId,
        'name' => $name,
    ]);
}

function socialIdentityConnection(Organization $organization, SocialIdentity $identity, ?int $enterpriseId = null): IntegrationConnection
{
    return IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'enterprise_id' => $enterpriseId,
        'social_identity_id' => $identity->id,
        'provider' => $identity->provider,
        'external_account_id' => $identity->external_id,
        'credential_reference' => 'vault/social/linkedin/member-opaque-01',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]);
}

it('scopes authenticated identity uniqueness to the owning organization and preserves opaque identifiers', function (): void {
    [$organization, , , $identity] = socialIdentityDomainContext();

    expect($identity->external_id)->toBe('member-opaque-01');

    expect(fn () => SocialIdentity::factory()->create([
        'organization_id' => $organization->id,
        'provider' => 'linkedin',
        'external_id' => 'member-opaque-01',
    ]))->toThrow(ValidationException::class);

    $otherOrganization = Organization::factory()->create();
    $otherIdentity = SocialIdentity::factory()->create([
        'organization_id' => $otherOrganization->id,
        'provider' => 'linkedin',
        'external_id' => 'member-opaque-01',
    ]);

    expect($otherIdentity->id)->not->toBe($identity->id)
        ->and(SocialIdentity::query()->where('provider', 'linkedin')->where('external_id', 'member-opaque-01')->count())->toBe(2);
});

it('keeps identity ownership and external identifiers immutable while allowing display-name changes', function (): void {
    [$organization, , , $identity] = socialIdentityDomainContext();
    $identity->name = 'Updated display name';
    $identity->save();

    expect($identity->refresh()->name)->toBe('Updated display name');

    $identity->external_id = 'different-member';
    expect(fn () => $identity->save())->toThrow(LogicException::class);

    $identity->refresh();
    $otherOrganization = Organization::factory()->create();
    $identity->organization_id = $otherOrganization->id;
    expect(fn () => $identity->save())->toThrow(LogicException::class);
});

it('allows one identity grant to authorize multiple destinations without duplicating grant state', function (): void {
    [$organization, $enterprise, $channel, $identity] = socialIdentityDomainContext();
    $connection = socialIdentityConnection($organization, $identity);

    $first = socialIdentityDestination($enterprise, $channel, $identity, 'page-100', 'Original Page');
    $second = socialIdentityDestination($enterprise, $channel, $identity, 'page-200', 'Campaign Page');

    expect($identity->destinations()->count())->toBe(2)
        ->and($identity->integrationConnection->is($connection))->toBeTrue()
        ->and($connection->credential_reference)->toBe('vault/social/linkedin/member-opaque-01')
        ->and($connection->isOperational())->toBeTrue()
        ->and($first->socialIdentity->is($identity))->toBeTrue()
        ->and($second->socialIdentity->is($identity))->toBeTrue();
});

it('rejects mismatched identity, network, organization and duplicate destination associations', function (): void {
    [$organization, $enterprise, $channel, $identity] = socialIdentityDomainContext();
    socialIdentityDestination($enterprise, $channel, $identity, 'page-100');

    expect(fn () => socialIdentityDestination($enterprise, $channel, $identity, 'page-100', 'Duplicate Page'))
        ->toThrow(ValidationException::class);

    expect(fn () => SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'social_identity_id' => $identity->id,
        'provider' => 'facebook',
        'external_id' => 'page-facebook-1',
    ]))->toThrow(LogicException::class);

    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->id]);
    $foreignChannel = Channel::factory()->create(['enterprise_id' => $foreignEnterprise->id]);

    expect(fn () => socialIdentityDestination($foreignEnterprise, $foreignChannel, $identity, 'foreign-page'))
        ->toThrow(LogicException::class);

    expect($organization->id)->not->toBe($foreignOrganization->id);
});

it('allows legacy destination identifiers in separate enterprise scopes without making them global identities', function (): void {
    [$organization, $enterprise, $channel] = socialIdentityDomainContext();
    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $otherChannel = Channel::factory()->create(['enterprise_id' => $otherEnterprise->id]);

    $first = SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->id,
        'channel_id' => $channel->id,
        'provider' => 'linkedin',
        'external_id' => 'legacy-destination-1',
    ]);
    $second = SocialAccount::factory()->create([
        'enterprise_id' => $otherEnterprise->id,
        'channel_id' => $otherChannel->id,
        'provider' => 'linkedin',
        'external_id' => 'legacy-destination-1',
    ]);

    expect($first->id)->not->toBe($second->id);
});

it('enforces one grant per identity and validates enterprise-scoped destination ownership', function (): void {
    [$organization, $enterprise, $channel, $identity] = socialIdentityDomainContext();
    socialIdentityDestination($enterprise, $channel, $identity, 'page-100');
    $connection = socialIdentityConnection($organization, $identity);

    expect(fn () => IntegrationConnection::query()->create([
        'organization_id' => $organization->id,
        'social_identity_id' => $identity->id,
        'provider' => $identity->provider,
        'external_account_id' => $identity->external_id,
        'credential_reference' => 'vault/second-grant',
        'status' => IntegrationConnection::STATUS_ACTIVE,
    ]))->toThrow(QueryException::class);

    $otherEnterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $connection->enterprise_id = $otherEnterprise->id;
    expect(fn () => $connection->save())->toThrow(LogicException::class);
});

it('requires explicit reauthorization with a replacement credential reference after revocation', function (): void {
    [$organization, , , $identity] = socialIdentityDomainContext();
    $connection = socialIdentityConnection($organization, $identity);
    $connection->status = IntegrationConnection::STATUS_REVOKED;
    expect(fn () => $connection->save())->toThrow(LogicException::class);
    $connection->refresh();
    $connection->transitionTo(IntegrationConnection::STATUS_REVOKED)->save();

    expect(fn () => $connection->transitionTo(IntegrationConnection::STATUS_ACTIVE))
        ->toThrow(LogicException::class);

    $connection->reauthorize('vault/social/linkedin/member-opaque-02')->save();

    expect($connection->refresh()->status)->toBe(IntegrationConnection::STATUS_ACTIVE)
        ->and($connection->credential_reference)->toBe('vault/social/linkedin/member-opaque-02');
});

it('snapshots network, destination and identity identifiers for historical publications', function (): void {
    [$organization, $enterprise, $channel, $identity] = socialIdentityDomainContext();
    socialIdentityConnection($organization, $identity);
    $account = socialIdentityDestination($enterprise, $channel, $identity, 'page-100', 'Original Page');

    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->id]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->id, 'marketing_strategy_id' => $strategy->id]);
    $content = ContentItem::factory()->create([
        'enterprise_id' => $enterprise->id,
        'campaign_id' => $campaign->id,
        'status' => ContentItem::STATUS_PUBLICATION_READY,
    ]);

    $publication = Publication::factory()->create([
        'enterprise_id' => $enterprise->id,
        'content_item_id' => $content->id,
        'channel_id' => $channel->id,
        'social_account_id' => $account->id,
    ]);

    $account->name = 'Renamed Page';
    $account->save();
    $account->external_id = 'page-101';
    expect(fn () => $account->save())->toThrow(LogicException::class);
    $account->refresh();

    expect($publication->target_provider)->toBe('linkedin')
        ->and($publication->target_external_id)->toBe('page-100')
        ->and($publication->target_name)->toBe('Original Page')
        ->and($publication->target_identity_external_id)->toBe('member-opaque-01');

    $publication->target_external_id = 'mutated-history';
    expect(fn () => $publication->save())->toThrow(LogicException::class);
});

it('authorizes identity visibility by organization while reserving mutation for administrators and the OAuth flow', function (): void {
    [$organization, , , $identity] = socialIdentityDomainContext();
    $owner = App\Models\User::factory()->create();
    $member = App\Models\User::factory()->create();
    $outsider = App\Models\User::factory()->create();
    App\Models\Membership::factory()->owner()->create(['user_id' => $owner->id, 'organization_id' => $organization->id]);
    App\Models\Membership::factory()->create(['role' => App\Enums\MembershipRole::Member, 'user_id' => $member->id, 'organization_id' => $organization->id]);

    expect(\Illuminate\Support\Facades\Gate::forUser($owner)->allows('view', $identity))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($member)->allows('view', $identity))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($owner)->allows('update', $identity))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Gate::forUser($member)->allows('update', $identity))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($outsider)->allows('view', $identity))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($owner)->allows('create', App\Models\SocialIdentity::class))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($owner)->allows('delete', $identity))->toBeFalse();
});

it('models expiration and reconnect-required as non-operational grant states', function (): void {
    [$organization, , , $identity] = socialIdentityDomainContext();
    $connection = socialIdentityConnection($organization, $identity);

    $connection->transitionTo(IntegrationConnection::STATUS_EXPIRED)->save();
    expect($connection->refresh()->isOperational())->toBeFalse();

    $connection->transitionTo(IntegrationConnection::STATUS_RECONNECT_REQUIRED)->save();
    expect($connection->refresh()->isOperational())->toBeFalse();

    $connection->reauthorize('vault/social/linkedin/member-opaque-01/rotated')->save();
    expect($connection->refresh()->isOperational())->toBeTrue();
});

it('allows one Meta authorization identity to expose destinations on multiple networks', function (): void {
    $organization = Organization::factory()->create();
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->id]);
    $facebookChannel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'type' => 'facebook']);
    $instagramChannel = Channel::factory()->create(['enterprise_id' => $enterprise->id, 'type' => 'instagram']);
    $identity = SocialIdentity::factory()->create([
        'organization_id' => $organization->id,
        'provider' => 'meta',
        'external_id' => 'meta-user-opaque-1',
    ]);

    $facebook = socialIdentityDestination($enterprise, $facebookChannel, $identity, 'shared-external-id', 'Facebook Page');
    $instagram = socialIdentityDestination($enterprise, $instagramChannel, $identity, 'shared-external-id', 'Instagram Account');

    expect($facebook->provider)->toBe('facebook')
        ->and($instagram->provider)->toBe('instagram')
        ->and($identity->destinations()->count())->toBe(2);
});