<?php

use App\Experts\MarketingExpert;
use App\Filament\Resources\Reports\ReportResource;
use App\Mcp\Servers\Cr8orServer;
use App\Mcp\Tools\GenerateCompetitiveAnalysisReportTool;
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
use App\Services\CompetitiveAnalysisReportingService;
use App\Services\StrategicContextService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

function competitiveAnalysisContext(): array
{
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $owner->getKey(),
        'organization_id' => $organization->getKey(),
    ]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization->getKey()]);

    return [$owner, $organization, $enterprise];
}

function competitiveAnalysisEvidence(Enterprise $enterprise, string $content = 'Pricing and product information from a captured source.'): KnowledgeReference
{
    $source = KnowledgeSource::factory()->create(['enterprise_id' => $enterprise->getKey()]);
    $document = KnowledgeDocument::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $source->getKey(),
        'title' => 'Competitor source document',
        'content' => $content,
    ]);
    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $source->getKey(),
        'knowledge_document_id' => $document->getKey(),
        'knowledge_context_id' => null,
        'title' => 'Competitor pricing source',
        'summary' => 'A captured pricing page.',
    ]);
    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'version' => 1,
        'content' => $content,
        'context_snapshot' => ['capture' => 'test'],
        'recorded_at' => now(),
    ]);

    return KnowledgeReference::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'knowledge_document_id' => $document->getKey(),
        'label' => 'Pricing source',
        'locator' => 'pricing',
    ]);
}

it('persists structured competitor profile fields through the versioned domain service and context contract', function () {
    [$owner, , $enterprise] = competitiveAnalysisContext();
    $evidence = competitiveAnalysisEvidence($enterprise);
    $service = app(StrategicContextService::class);

    $first = $service->createCompetitor(
        $owner,
        $enterprise,
        'Rival One',
        'https://rival.example.test',
        'Value-focused alternative',
        ['wide distribution'],
        ['limited support'],
        [
            'products' => [['name' => 'Starter', 'features' => ['analytics', 'exports'], 'differentiators' => []]],
            'target_segments' => [['name' => 'Small businesses', 'needs' => ['low setup cost']]],
            'pricing' => [['model' => 'subscription', 'amount' => '19', 'currency' => 'eur']],
            'markets' => [['geography' => 'Romania', 'languages' => ['ro', 'en']]],
            'differentiators' => [['claim' => 'Simple onboarding', 'claim_type' => 'competitor_claim']],
            'channels' => [['channel_type' => 'sales', 'name' => 'Direct sales']],
            'reputation' => [['platform' => 'Review site', 'sentiment' => 'mixed', 'rating' => 4.2, 'rating_scale' => '5 stars']],
            'digital_presence' => [['channel' => 'Website', 'observation' => 'Pricing page is public', 'url' => 'https://rival.example.test/pricing']],
            'source_references' => [['knowledge_reference_id' => $evidence->getKey(), 'field' => 'pricing', 'claim' => 'Pricing page captured', 'observed_at' => '2026-10-17']],
        ],
    );

    $context = $service->context($owner, $enterprise);
    $second = $service->reviseCompetitor(
        $owner,
        $first,
        'Rival One',
        'https://rival.example.test',
        'Premium alternative',
        ['brand recognition'],
        ['price'],
        ['products' => [['name' => 'Pro', 'features' => ['automation'], 'differentiators' => []]]],
    );

    expect($first->refresh()->version)->toBe(1)
        ->and($first->status)->toBe(Competitor::STATUS_ARCHIVED)
        ->and($first->products)->toBe([['name' => 'Starter', 'features' => ['analytics', 'exports'], 'differentiators' => []]])
        ->and($second->version)->toBe(2)
        ->and($second->products)->toBe([['name' => 'Pro', 'features' => ['automation'], 'differentiators' => []]])
        ->and($context['competitors'][0]['products'])->toBe([['name' => 'Starter', 'features' => ['analytics', 'exports'], 'differentiators' => []]])
        ->and($context['competitors'][0]['source_references'][0]['knowledge_reference_id'])->toBe($evidence->getKey())
        ->and($first->pricing)->toBe([['model' => 'subscription', 'amount' => 19, 'currency' => 'EUR']]);
});

it('rejects malformed structured data and cross-Enterprise Knowledge references', function () {
    [$owner, , $enterprise] = competitiveAnalysisContext();
    $foreignOrganization = Organization::factory()->create();
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    $foreignEvidence = competitiveAnalysisEvidence($foreignEnterprise);
    $service = app(StrategicContextService::class);

    expect(fn () => $service->createCompetitor(
        $owner,
        $enterprise,
        'Malformed Rival',
        profile: ['pricing' => [['model' => 'subscription', 'minimum_amount' => 100, 'maximum_amount' => 10]]],
    ))->toThrow(ValidationException::class);

    expect(fn () => $service->createCompetitor(
        $owner,
        $enterprise,
        'Cross-Tenant Rival',
        profile: ['source_references' => [['knowledge_reference_id' => $foreignEvidence->getKey()]]],
    ))->toThrow(ValidationException::class);
});

it('rejects competitive-analysis reports without a structured result payload', function () {
    [, , $enterprise] = competitiveAnalysisContext();

    expect(fn () => Report::query()->create([
        'enterprise_id' => $enterprise->getKey(),
        'report_type' => CompetitiveAnalysisReportingService::REPORT_TYPE,
        'status' => Report::STATUS_COMPLETED,
        'period_start' => now(),
        'period_end' => now(),
        'generated_at' => now(),
        'methodology_version' => CompetitiveAnalysisReportingService::METHODOLOGY_VERSION,
    ]))->toThrow(LogicException::class);
});

it('creates an immutable report with exact profile and Knowledge version provenance', function () {
    [$owner, , $enterprise] = competitiveAnalysisContext();
    $evidence = competitiveAnalysisEvidence($enterprise, 'Immutable source content for this report.');
    $service = app(StrategicContextService::class);
    $service->createCompetitor(
        $owner,
        $enterprise,
        'Rival One',
        profile: [
            'products' => [['name' => 'Core product']],
            'pricing' => [['model' => 'subscription', 'amount' => 20, 'currency' => 'EUR']],
            'source_references' => [['knowledge_reference_id' => $evidence->getKey(), 'field' => 'pricing', 'claim' => 'Published subscription price']],
        ],
    );
    $service->createCompetitor($owner, $enterprise, 'Rival Two', profile: [
        'products' => [['name' => 'Alternative product']],
    ]);

    $reports = app(CompetitiveAnalysisReportingService::class);
    $report = $reports->generate($owner, $enterprise);
    $snapshot = $report->snapshot;
    $inputCompetitors = data_get($snapshot->source_records, 'inputs.competitors');

    expect($report->report_type)->toBe(CompetitiveAnalysisReportingService::REPORT_TYPE)
        ->and($report->status)->toBe(Report::STATUS_COMPLETED)
        ->and($report->methodology_version)->toBe(CompetitiveAnalysisReportingService::METHODOLOGY_VERSION)
        ->and($report->result_payload['scope']['competitor_count'])->toBe(2)
        ->and($report->result_payload['comparisons']['products']['competitors'])->toHaveCount(2)
        ->and($report->result_payload['source_fingerprint'])->toBe($snapshot->source_fingerprint)
        ->and(strlen($snapshot->source_fingerprint))->toBe(64)
        ->and($inputCompetitors)->toHaveCount(2)
        ->and(data_get($inputCompetitors[0], 'profile.products.0.name'))->toBe('Core product')
        ->and(data_get($inputCompetitors[0], 'evidence.0.knowledge_version.content'))->toBe('Immutable source content for this report.')
        ->and(data_get($inputCompetitors[0], 'evidence.0.document.content'))->toBe('Immutable source content for this report.')
        ->and(data_get($report->result_payload, 'evidence.0.knowledge_version'))->not->toHaveKey('content');

    $sameInputsReport = $reports->generate($owner, $enterprise);
    expect($sameInputsReport->snapshot->source_fingerprint)->toBe($snapshot->source_fingerprint);

    expect(fn () => $report->update(['result_payload' => ['tampered' => true]]))
        ->toThrow(LogicException::class);
    expect(fn () => $snapshot->update(['source_fingerprint' => str_repeat('0', 64)]))
        ->toThrow(LogicException::class);
});

it('keeps an existing report stable after a competitor profile is revised', function () {
    [$owner, , $enterprise] = competitiveAnalysisContext();
    $service = app(StrategicContextService::class);
    $first = $service->createCompetitor($owner, $enterprise, 'Rival', profile: [
        'products' => [['name' => 'Version one product']],
    ]);

    $report = app(CompetitiveAnalysisReportingService::class)->generate($owner, $enterprise, [$first->getKey()]);
    $payloadBefore = $report->result_payload;
    $fingerprintBefore = $report->snapshot->source_fingerprint;

    $service->reviseCompetitor($owner, $first, 'Rival', profile: [
        'products' => [['name' => 'Version two product']],
    ]);

    $report->refresh();
    $report->load('snapshot');

    expect($report->result_payload)->toBe($payloadBefore)
        ->and($report->snapshot->source_fingerprint)->toBe($fingerprintBefore)
        ->and(data_get($report->snapshot->source_records, 'inputs.competitors.0.profile.products.0.name'))->toBe('Version one product');
});

it('denies cross-Enterprise report generation and exposes the governed capability through Marketing Expert', function () {
    [$owner, , $enterprise] = competitiveAnalysisContext();
    $foreignOrganization = Organization::factory()->create();
    $foreignOwner = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $foreignOwner->getKey(),
        'organization_id' => $foreignOrganization->getKey(),
    ]);
    $foreignEnterprise = Enterprise::factory()->create(['organization_id' => $foreignOrganization->getKey()]);
    $foreignCompetitor = app(StrategicContextService::class)->createCompetitor($foreignOwner, $foreignEnterprise, 'Foreign Rival');

    expect(fn () => app(CompetitiveAnalysisReportingService::class)->generate($owner, $foreignEnterprise))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(CompetitiveAnalysisReportingService::class)->generate($owner, $enterprise, [$foreignCompetitor->getKey()]))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(CompetitiveAnalysisReportingService::class)->generate($owner, $enterprise, ['not-an-id']))
        ->toThrow(ValidationException::class)
        ->and(app(MarketingExpert::class)->capabilities())->toContain('marketing.competitor.report.generate');
});

it('generates competitive analysis through the registered MCP capability', function () {
    [$owner, , $enterprise] = competitiveAnalysisContext();
    app(StrategicContextService::class)->createCompetitor($owner, $enterprise, 'MCP Rival');

    Cr8orServer::actingAs($owner, 'api')
        ->tool(GenerateCompetitiveAnalysisReportTool::class, ['enterprise_id' => $enterprise->getKey()])
        ->assertOk()
        ->assertSee(['competitive_analysis', 'MCP Rival', 'source_fingerprint']);
});

it('renders a persisted competitive-analysis report through the authorized Reports resource', function () {
    [$owner, , $enterprise] = competitiveAnalysisContext();
    app(StrategicContextService::class)->createCompetitor($owner, $enterprise, 'Report UI Rival');
    $report = app(CompetitiveAnalysisReportingService::class)->generate($owner, $enterprise);

    $this->actingAs($owner)
        ->get(ReportResource::getUrl('view', ['record' => $report]))
        ->assertOk()
        ->assertSee('Report details')
        ->assertSee('Executive summary')
        ->assertSee('Report UI Rival')
        ->assertSee($report->snapshot->source_fingerprint);

    $foreignOrganization = Organization::factory()->create();
    $foreignUser = User::factory()->create();
    Membership::factory()->owner()->create([
        'user_id' => $foreignUser->getKey(),
        'organization_id' => $foreignOrganization->getKey(),
    ]);

    $this->actingAs($foreignUser)
        ->get(ReportResource::getUrl('view', ['record' => $report]))
        ->assertNotFound();
});
