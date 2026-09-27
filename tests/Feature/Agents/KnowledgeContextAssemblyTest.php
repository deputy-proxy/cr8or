<?php

use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use App\Services\KnowledgeContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;

it('returns authorized knowledge with source and version provenance', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $source = KnowledgeSource::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Operations handbook',
        'uri' => 'https://example.test/handbook',
    ]);

    $context = KnowledgeContext::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'name' => 'Operations',
    ]);

    $document = KnowledgeDocument::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $source->getKey(),
        'title' => 'Operations handbook',
        'identifier' => 'OPS-001',
    ]);

    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_source_id' => $source->getKey(),
        'knowledge_document_id' => $document->getKey(),
        'knowledge_context_id' => $context->getKey(),
        'title' => 'Approval threshold',
        'summary' => 'Approvals are required above the configured threshold.',
    ]);

    KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'version' => 1,
        'content' => 'Old approval rule.',
    ]);

    $version = KnowledgeVersion::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'version' => 2,
        'content' => 'Current approval rule.',
    ]);

    KnowledgeReference::factory()->create([
        'enterprise_id' => $enterprise->getKey(),
        'knowledge_item_id' => $item->getKey(),
        'knowledge_document_id' => $document->getKey(),
        'label' => 'Approval section',
        'locator' => 'section-4',
    ]);

    $data = app(KnowledgeContextAssembler::class)->assemble($user, $enterprise);

    expect($data['items'])->toHaveCount(1)
        ->and($data['items'][0]['id'])->toBe($item->getKey())
        ->and($data['items'][0]['source'])->toMatchArray([
            'id' => $source->getKey(),
            'name' => 'Operations handbook',
            'uri' => 'https://example.test/handbook',
        ])
        ->and($data['items'][0]['document'])->toMatchArray([
            'id' => $document->getKey(),
            'identifier' => 'OPS-001',
        ])
        ->and($data['items'][0]['context']['id'])->toBe($context->getKey())
        ->and($data['items'][0]['version'])->toMatchArray([
            'id' => $version->getKey(),
            'version' => 2,
            'content' => 'Current approval rule.',
        ])
        ->and($data['items'][0]['references'][0])->toMatchArray([
            'label' => 'Approval section',
            'locator' => 'section-4',
            'document_id' => $document->getKey(),
        ]);
});

it('denies knowledge retrieval outside the users organization', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $foreignOrganization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $foreignEnterprise = Enterprise::factory()->create([
        'organization_id' => $foreignOrganization->getKey(),
    ]);

    expect(fn () => app(KnowledgeContextAssembler::class)->assemble($user, $foreignEnterprise))
        ->toThrow(AuthorizationException::class);
});

it('returns an empty bounded result when an enterprise has no knowledge', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    $data = app(KnowledgeContextAssembler::class)->assemble($user, $enterprise);

    expect($data['enterprise']['id'])->toBe($enterprise->getKey())
        ->and($data['contexts'])->toBe([])
        ->and($data['items'])->toBe([]);
});

it('bounds knowledge item retrieval', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();

    Membership::factory()->create([
        'user_id' => $user->getKey(),
        'organization_id' => $organization->getKey(),
    ]);

    $enterprise = Enterprise::factory()->create([
        'organization_id' => $organization->getKey(),
    ]);

    KnowledgeItem::factory()->count(101)->create([
        'enterprise_id' => $enterprise->getKey(),
    ]);

    $data = app(KnowledgeContextAssembler::class)->assemble($user, $enterprise);

    expect($data['items'])->toHaveCount(100);
});