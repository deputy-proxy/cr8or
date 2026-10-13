<?php

use App\Models\ContentItem;

it('persists provider-specific content metadata as a JSON object', function () {
    $metadata = [
        'youtube' => [
            'privacy_status' => 'private',
            'category_id' => '22',
            'tags' => ['product launch', 'cr8or'],
            'made_for_kids' => false,
        ],
    ];

    $item = ContentItem::factory()->create(['metadata' => $metadata])->refresh();

    expect($item->metadata)->toBe($metadata)
        ->and($item->getRawOriginal('metadata'))->toBeString()
        ->and(json_decode($item->getRawOriginal('metadata'), true))->toBe($metadata);
});

it('prevents changing publishing metadata after content approval', function () {
    $item = ContentItem::factory()->create([
        'metadata' => ['youtube' => ['privacy_status' => 'private']],
    ]);
    $item->transitionTo(ContentItem::STATUS_IN_REVIEW)->save();
    $item->transitionTo(ContentItem::STATUS_APPROVED)->save();

    expect(fn () => $item->update(['metadata' => ['youtube' => ['privacy_status' => 'public']]]))
        ->toThrow(\LogicException::class, 'metadata cannot be silently changed');
});

it('rejects non-object metadata at the domain model boundary', function () {
    expect(fn () => ContentItem::factory()->create(['metadata' => 'not-an-object']))
        ->toThrow(\LogicException::class, 'metadata must be a JSON object');

    expect(fn () => ContentItem::factory()->create(['metadata' => ['not', 'an', 'object']]))
        ->toThrow(\LogicException::class, 'metadata must be a JSON object');
});