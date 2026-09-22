<?php

use App\Models\Enterprise;
use App\Models\KnowledgeContext;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeReference;
use App\Models\KnowledgeSource;
use App\Models\KnowledgeSpecification;
use App\Models\KnowledgeVersion;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

it('persists the knowledge domain and its relationships', function () {
    $enterprise = Enterprise::factory()->create();
    $source = KnowledgeSource::factory()->create(['enterprise_id' => $enterprise]);
    $context = KnowledgeContext::factory()->create(['enterprise_id' => $enterprise]);
    $document = KnowledgeDocument::factory()->create(['enterprise_id' => $enterprise, 'knowledge_source_id' => $source]);
    $item = KnowledgeItem::factory()->create([
        'enterprise_id' => $enterprise,
        'knowledge_source_id' => $source,
        'knowledge_document_id' => $document,
        'knowledge_context_id' => $context,
    ]);
    $version = KnowledgeVersion::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item, 'version' => 1]);
    $reference = KnowledgeReference::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item, 'knowledge_document_id' => $document]);
    $specification = KnowledgeSpecification::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item]);

    expect($item->enterprise->is($enterprise))->toBeTrue()
        ->and($item->source->is($source))->toBeTrue()
        ->and($item->document->is($document))->toBeTrue()
        ->and($item->context->is($context))->toBeTrue()
        ->and($item->versions->contains($version))->toBeTrue()
        ->and($item->references->contains($reference))->toBeTrue()
        ->and($item->specifications->contains($specification))->toBeTrue();
});

it('denies knowledge access across organizations server side', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();
    $owner = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    $record = KnowledgeItem::factory()->create(['enterprise_id' => Enterprise::factory()->create(['organization_id' => $otherOrganization])]);

    expect(Gate::forUser($owner)->allows('view', $record))->toBeFalse()
        ->and(Gate::forUser($owner)->allows('update', $record))->toBeFalse();
});

it('allows enterprise owners to manage knowledge and members to read it', function () {
    $organization = Organization::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $owner, 'organization_id' => $organization]);
    Membership::factory()->create(['user_id' => $member, 'organization_id' => $organization]);
    $enterprise = Enterprise::factory()->create(['organization_id' => $organization]);
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);

    expect(Gate::forUser($owner)->allows('view', $item))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $item))->toBeTrue()
        ->and(Gate::forUser($member)->allows('view', $item))->toBeTrue()
        ->and(Gate::forUser($member)->allows('update', $item))->toBeFalse();
});

it('preserves historical knowledge versions', function () {
    $enterprise = Enterprise::factory()->create();
    $item = KnowledgeItem::factory()->create(['enterprise_id' => $enterprise]);
    $first = KnowledgeVersion::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item, 'version' => 1, 'content' => 'Original']);
    $second = KnowledgeVersion::factory()->create(['enterprise_id' => $enterprise, 'knowledge_item_id' => $item, 'content' => 'Updated']);

    expect($second->version)->toBe(2)
        ->and($first->refresh()->content)->toBe('Original')
        ->and($item->versions()->orderBy('version')->pluck('version')->all())->toBe([1, 2]);
});

it('keeps the knowledge schema organization and version boundaries explicit', function () {
    expect(Schema::getColumnListing('knowledge_sources'))->toContain('enterprise_id')
        ->and(Schema::getColumnListing('knowledge_items'))->toContain('enterprise_id', 'knowledge_context_id')
        ->and(Schema::getColumnListing('knowledge_versions'))->toContain('enterprise_id', 'knowledge_item_id', 'version', 'context_snapshot')
        ->and(Schema::getColumnListing('knowledge_references'))->toContain('enterprise_id', 'knowledge_item_id')
        ->and(Schema::getColumnListing('knowledge_specifications'))->toContain('enterprise_id');
});
