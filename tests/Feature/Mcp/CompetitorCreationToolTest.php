<?php

use App\Capabilities\CapabilityRegistry;
use App\Experts\MarketingExpert;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\CreateCompetitorRecordTool;
use App\Mcp\Tools\CreateCompetitorTool;
use App\Models\Competitor;
use App\Models\Enterprise;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Report;
use App\Models\User;
use App\Operations\CreateCompetitor;
use App\Services\ExpertCapabilityResolver;
use App\Services\StrategicContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

function competitorToolKnowledgeReference(Enterprise $enterprise): KnowledgeReference
{
    $source = KnowledgeSource::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $document = KnowledgeDocument::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $source->getKey(),
        'title' => 'Competitor product evidence',
        'content' => 'The competitor product includes analytics.',
    ]);
    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $source->getKey(),
        'knowledge_document_id' => $document->getKey(),
        'knowledge_context_id' => null,
        'title' => 'Competitor product page',
        'summary' => 'Product feature evidence.',
    ]);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'version' => 1,
        'content' => 'The competitor product includes analytics.',
        'context_snapshot' => ['capture' => 'test'],
        'recorded_at' => now(),
    ]);

    return KnowledgeReference::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'knowledge_document_id' => $document->getKey(),
        'label' => 'Product feature source',
        'locator' => 'features',
    ]);
}

function competitorToolOwner(Organization $organization): User
{
    $user = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    return $user;
}

it('registers the competitor create capability on the Marketing Expert through one Operation and Tool', function () {
    $definition = app(CapabilityRegistry::class)->forTool(CreateCompetitorTool::class);

    $recordDefinition = app(CapabilityRegistry::class)->forTool(CreateCompetitorRecordTool::class);

    expect($definition->key)->toBe('marketing.competitor.create_and_report')
        ->and($definition->operation)->toBe(App\Operations\CreateCompetitorAndGenerateReport::class)
        ->and($definition->tool)->toBe('create-competitor')
        ->and($recordDefinition->key)->toBe('marketing.competitor.create')
        ->and($recordDefinition->operation)->toBe(CreateCompetitor::class)
        ->and($recordDefinition->tool)->toBe('create-competitor-record')
        ->and(app(MarketingExpert::class)->capabilities())->toContain('marketing.competitor.create', 'marketing.competitor.create_and_report')
        ->and(app(ExpertCapabilityResolver::class)->resolve('marketing', 'marketing.competitor.create')->operation)->toBe(CreateCompetitor::class)
        ->and(app(ExpertCapabilityResolver::class)->resolve('marketing', 'marketing.competitor.create_and_report')->operation)->toBe(App\Operations\CreateCompetitorAndGenerateReport::class)
        ->and(app(App\Experts\CopywritingExpert::class)->capabilities())->not->toContain('marketing.competitor.create');

    expect(fn () => app(ExpertCapabilityResolver::class)->resolve('copywriting', 'marketing.competitor.create'))
        ->toThrow(AuthorizationException::class);
});

it('creates a persisted enterprise-scoped competitor through the governed MCP boundary', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => '  Competitor Alpha  ',
            'website' => 'https://alpha.example.test',
            'positioning' => 'Value-focused alternative',
            'strengths' => ['distribution', 'price'],
            'weaknesses' => ['brand awareness'],
        ])
        ->assertOk()
        ->assertSee(['Competitor Alpha', 'Value-focused alternative', 'distribution']);

    $competitor = Competitor::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    expect($competitor->name)->toBe('Competitor Alpha')
        ->and($competitor->version)->toBe(1)
        ->and($competitor->website)->toBe('https://alpha.example.test')
        ->and($competitor->strengths)->toBe(['distribution', 'price'])
        ->and($competitor->weaknesses)->toBe(['brand awareness'])
        ->and($competitor->is_current)->toBeTrue();

    $report = Report::query()->where('enterprise_id', $enterprise->getKey())->where('report_type', 'competitive_analysis')->firstOrFail();
    $snapshotCompetitors = data_get($report->snapshot->source_records, 'inputs.competitors', []);

    expect($report->status)->toBe(Report::STATUS_COMPLETED)
        ->and($report->result_payload['scope']['competitor_count'])->toBe(1)
        ->and(collect($snapshotCompetitors)->pluck('competitor_id')->map(fn ($id) => (int) $id)->all())->toContain((int) $competitor->getKey())
        ->and($report->snapshot->source_fingerprint)->toBe($report->result_payload['source_fingerprint']);
});

it('preserves the record-only MCP tool for callers that do not need a report', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorRecordTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Record Only Rival',
        ])
        ->assertOk()
        ->assertSee(['Record Only Rival']);

    expect(Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Record Only Rival')->exists())->toBeTrue()
        ->and(Report::query()->where('enterprise_id', $enterprise->getKey())->where('report_type', 'competitive_analysis')->exists())->toBeFalse();
});

it('versions a repeated competitor name without overwriting historical data', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $server = Cr8orServer::actingAs($actor, 'api');

    $server->tool(CreateCompetitorTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Competitor Beta',
        'positioning' => 'Original position',
    ])->assertOk();

    $first = Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Competitor Beta')->firstOrFail();

    $server->tool(CreateCompetitorTool::class, [
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Competitor Beta',
        'positioning' => 'Updated position',
    ])->assertOk();

    $second = Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Competitor Beta')->where('is_current', true)->firstOrFail();

    expect($first->refresh()->version)->toBe(1)
        ->and($first->status)->toBe(Competitor::STATUS_ARCHIVED)
        ->and($first->positioning)->toBe('Original position')
        ->and($second->version)->toBe(2)
        ->and($second->supersedes_id)->toBe($first->getKey())
        ->and($second->positioning)->toBe('Updated position')
        ->and(Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'Competitor Beta')->count())->toBe(2);

    $reports = Report::query()->where('enterprise_id', $enterprise->getKey())->where('report_type', 'competitive_analysis')->with('snapshot')->orderBy('id')->get();
    expect($reports)->toHaveCount(2)
        ->and(collect(data_get($reports[0]->snapshot->source_records, 'inputs.competitors', []))->pluck('competitor_id')->map(fn ($id) => (int) $id)->all())->toContain((int) $first->getKey())
        ->and(collect(data_get($reports[1]->snapshot->source_records, 'inputs.competitors', []))->pluck('competitor_id')->map(fn ($id) => (int) $id)->all())->toContain((int) $second->getKey())
        ->and(collect(data_get($reports[1]->snapshot->source_records, 'inputs.competitors', []))->pluck('competitor_id')->map(fn ($id) => (int) $id)->all())->not->toContain((int) $first->getKey());
});

it('denies creation under an enterprise outside the authenticated user organization', function () {
    $actorOrganization = Organization::factory()->create();
    $actor = competitorToolOwner($actorOrganization);
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $foreignEnterprise->getKey(),
            'name' => 'Must not persist',
        ])
        ->assertHasErrors();

    expect(Competitor::query()->where('enterprise_id', $foreignEnterprise->getKey())->exists())->toBeFalse();
});

it('denies a member without enterprise create permission and does not leak competitor data', function () {
    $organization = Organization::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->create([
        'user_id' => $member->getKey(),
        'organization_id' => $organization->getKey(),
        'role' => 'member',
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($member, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Unauthorized competitor',
        ])
        ->assertHasErrors();

    expect(Competitor::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('rejects invalid URLs and malformed strength lists before persistence', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Invalid competitor',
            'website' => 'not a URL',
            'strengths' => ['valid', ['malformed']],
        ])
        ->assertHasErrors();

    expect(Competitor::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse();
});

it('enforces create policy and domain validation when the Operation is called without the MCP adapter', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    expect(fn () => app(CreateCompetitor::class)->execute($actor, [
        'enterprise' => $enterprise,
        'name' => 'Invalid direct call',
        'website' => 'not a URL',
    ]))->toThrow(ValidationException::class);

    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);

    expect(fn () => app(StrategicContextService::class)->createCompetitor($actor, $foreignEnterprise, 'Forbidden'))
        ->toThrow(AuthorizationException::class);
});
it('accepts structured profile fields through the governed competitor capability', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $reference = competitorToolKnowledgeReference($enterprise);

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Structured Competitor',
            'products' => [['name' => 'Core', 'features' => ['analytics']]],
            'pricing' => [['model' => 'subscription', 'amount' => 29, 'currency' => 'EUR']],
            'markets' => [['geography' => 'Romania', 'languages' => ['ro', 'en']]],
            'target_segments' => [['name' => 'Small businesses', 'needs' => ['low setup cost']]],
            'differentiators' => [['claim' => 'Simple onboarding', 'claim_type' => 'competitor_claim']],
            'channels' => [['channel_type' => 'sales', 'name' => 'Direct sales']],
            'reputation' => [['platform' => 'Review site', 'rating' => 4.2, 'rating_scale' => '5', 'review_count' => 12, 'sentiment' => 'positive', 'observed_at' => '2026-10-18']],
            'digital_presence' => [['channel' => 'SEO', 'url' => 'https://competitor.example.test', 'observation' => 'Has a product landing page', 'observed_at' => '2026-10-18']],
            'source_references' => [['knowledge_reference_id' => $reference->getKey(), 'field' => 'products', 'claim' => 'Core product includes analytics']],
        ])
        ->assertOk()
        ->assertSee(['Structured Competitor', 'Core', 'Romania']);

    $competitor = Competitor::query()->where('enterprise_id', $enterprise->getKey())->firstOrFail();

    expect($competitor->products)->toBe([['name' => 'Core', 'features' => ['analytics'], 'differentiators' => []]])
        ->and($competitor->pricing)->toBe([['model' => 'subscription', 'amount' => 29, 'currency' => 'EUR']])
        ->and($competitor->markets)->toBe([['geography' => 'Romania', 'languages' => ['ro', 'en'], 'industries' => []]])
        ->and($competitor->target_segments[0]['name'])->toBe('Small businesses')
        ->and($competitor->differentiators[0]['claim_type'])->toBe('competitor_claim')
        ->and($competitor->channels[0]['channel_type'])->toBe('sales')
        ->and($competitor->reputation[0]['review_count'])->toBe(12)
        ->and($competitor->digital_presence[0]['channel'])->toBe('SEO')
        ->and($competitor->source_references[0]['knowledge_reference_id'])->toBe($reference->getKey());

    $report = Report::query()->where('enterprise_id', $enterprise->getKey())->where('report_type', 'competitive_analysis')->with('snapshot')->firstOrFail();
    expect(data_get($report->result_payload, 'evidence.0.available'))->toBeTrue()
        ->and(data_get($report->snapshot->source_records, 'inputs.competitors.0.evidence.0.knowledge_version.content'))->toBe('The competitor product includes analytics.');
});

it('rolls back competitor creation if competitive report persistence fails', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    Report::creating(function (Report $report): void {
        if ($report->report_type === 'competitive_analysis') {
            throw new RuntimeException('Simulated report persistence failure.');
        }
    });

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'Atomic competitor',
        ])
        ->assertHasErrors();

    expect(Competitor::query()->where('enterprise_id', $enterprise->getKey())->exists())->toBeFalse()
        ->and(Report::query()->where('enterprise_id', $enterprise->getKey())->where('report_type', 'competitive_analysis')->exists())->toBeFalse();
});

it('includes current competitors from the target Enterprise only in the generated report', function () {
    $organization = Organization::factory()->create();
    $actor = competitorToolOwner($organization);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);
    $existing = app(StrategicContextService::class)->createCompetitor($actor, $enterprise, 'Existing Local Rival');

    $foreignOrganization = Organization::factory()->create();
    $foreignActor = competitorToolOwner($foreignOrganization);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    $foreignCompetitor = app(StrategicContextService::class)->createCompetitor($foreignActor, $foreignEnterprise, 'Foreign Rival');

    Cr8orServer::actingAs($actor, 'api')
        ->tool(CreateCompetitorTool::class, [
            'enterprise_id' => $enterprise->getKey(),
            'name' => 'New Local Rival',
        ])
        ->assertOk();

    $report = Report::query()->where('enterprise_id', $enterprise->getKey())->where('report_type', 'competitive_analysis')->with('snapshot')->firstOrFail();
    $capturedIds = collect(data_get($report->snapshot->source_records, 'inputs.competitors', []))
        ->pluck('competitor_id')->map(fn ($id) => (int) $id)->all();
    $newCompetitorId = (int) Competitor::query()->where('enterprise_id', $enterprise->getKey())->where('name', 'New Local Rival')->value('id');

    expect($capturedIds)->toContain((int) $existing->getKey())
        ->toContain($newCompetitorId)
        ->not->toContain((int) $foreignCompetitor->getKey())
        ->and($report->enterprise_id)->toBe($enterprise->getKey());
});
