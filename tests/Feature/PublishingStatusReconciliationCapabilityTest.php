<?php

use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\GetPublicationTool;
use App\Mcp\Tools\ListPublicationResultsTool;
use App\Mcp\Tools\ListPublicationTool;
use App\Mcp\Tools\ReconcilePublicationStatusTool;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\MarketingStrategy;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Publication;
use App\Models\PublicationResult;
use App\Models\SocialAccount;
use App\Models\User;
use App\Operations\ReconcilePublicationStatus;
use Illuminate\Auth\Access\AuthorizationException;

function statusReconciliationContext(string $suffix = '123'): array
{
    $organization = Organization::factory()->create();
    $actor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $actor->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $channel = Channel::factory()->create(['enterprise_id' => $enterprise->getKey(), 'name' => 'LinkedIn', 'type' => 'social']);
    $strategy = MarketingStrategy::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $campaign = Campaign::factory()->create(['enterprise_id' => $enterprise->getKey(), 'marketing_strategy_id' => $strategy->getKey()]);
    $content = ContentItem::factory()->forCampaign($campaign)->create([
        'channel_id' => $channel->getKey(),
        'status' => ContentItem::STATUS_PUBLICATION_READY,
    ]);
    $account = SocialAccount::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'channel_id' => $channel->getKey(),
        'provider' => 'linkedin',
        'name' => 'LinkedIn destination',
        'external_id' => 'linkedin-user-'.$suffix,
        'status' => SocialAccount::STATUS_ACTIVE,
    ]);
    $publication = Publication::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'content_item_id' => $content->getKey(),
        'channel_id' => $channel->getKey(),
        'social_account_id' => $account->getKey(),
        'status' => Publication::STATUS_SCHEDULED,
        'scheduled_at' => now()->subMinute(),
    ]);
    $publication->markSubmitted('remote-post-123', 'https://www.linkedin.com/feed/update/remote-post-123')->save();

    return [$actor, $organization, $enterprise, $publication];
}

it('reconciles publication status through the governed Operation and returns the latest sanitized result', function (): void {
    [$actor, , , $publication] = statusReconciliationContext();
    $publication->succeed()->save();

    $resultRecord = PublicationResult::query()->create([
        'enterprise_id' => $publication->enterprise_id,
        'publication_id' => $publication->getKey(),
        'provider' => $publication->target_provider,
        'network' => $publication->target_provider,
        'destination_external_id' => $publication->target_external_id,
        'provider_status' => 'succeeded',
        'external_id' => 'remote-post-123',
        'external_url' => 'https://www.linkedin.com/feed/update/remote-post-123',
        'failure_code' => null,
        'failure_reason' => null,
        'payload' => ['access_token' => 'fake-secret-token', 'status' => 'succeeded'],
        'recorded_at' => now(),
    ]);

    $result = app(ReconcilePublicationStatus::class)->execute($actor, [
        'publication_id' => $publication->getKey(),
    ]);

    expect($result['publication_id'])->toBe($publication->getKey())
        ->and($result['reconciliation_status'])->toBe('published')
        ->and($result['status'])->toBe(Publication::STATUS_SUCCEEDED)
        ->and($result['external_id'])->toBe('remote-post-123')
        ->and($result['latest_result_id'])->toBe($resultRecord->getKey())
        ->and($result)->not->toHaveKey('payload')
        ->and(json_encode($result))->not->toContain('fake-secret-token');
});

it('denies direct Operation reconciliation outside the actor enterprise', function (): void {
    [, , , $publication] = statusReconciliationContext();
    $otherOrganization = Organization::factory()->create();
    $otherActor = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $otherActor->getKey(),
        'organization_id' => $otherOrganization->getKey(),
    ]);

    expect(fn () => app(ReconcilePublicationStatus::class)->execute($otherActor, [
        'publication_id' => $publication->getKey(),
    ]))->toThrow(AuthorizationException::class);
});
it('exposes scoped publication status and sanitized reconciliation history through MCP', function (): void {
    [$actor, , , $publication] = statusReconciliationContext();
    $publication->succeed()->save();

    PublicationResult::query()->create([
        'enterprise_id' => $publication->enterprise_id,
        'publication_id' => $publication->getKey(),
        'provider' => $publication->target_provider,
        'network' => $publication->target_provider,
        'destination_external_id' => $publication->target_external_id,
        'provider_status' => 'succeeded',
        'external_id' => 'remote-post-123',
        'external_url' => 'https://www.linkedin.com/feed/update/remote-post-123',
        'payload' => ['access_token' => 'fake-secret-token', 'status' => 'succeeded'],
        'recorded_at' => now(),
    ]);

    [, , , $foreignPublication] = statusReconciliationContext('456');
    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(ListPublicationTool::class, ['per_page' => 50])
        ->assertOk()
        ->assertSee('linkedin-user-123')
        ->assertDontSee('x-user-456');

    $server->tool(GetPublicationTool::class, ['id' => $publication->getKey()])
        ->assertOk()
        ->assertSee('remote-post-123')
        ->assertDontSee('fake-secret-token');

    $server->tool(ListPublicationResultsTool::class, ['publication_id' => $publication->getKey()])
        ->assertOk()
        ->assertSee('remote-post-123')
        ->assertDontSee('fake-secret-token');

    $server->tool(ReconcilePublicationStatusTool::class, ['publication_id' => $publication->getKey()])
        ->assertOk()
        ->assertSee('published')
        ->assertDontSee('fake-secret-token');

    expect($foreignPublication->enterprise_id)->not->toBe($publication->enterprise_id);
});
