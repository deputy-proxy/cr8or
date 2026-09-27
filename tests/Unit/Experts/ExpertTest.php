<?php

use App\Experts\Expert;
use App\Experts\ExpertDefinition;

it('exposes authoritative expert metadata through the canonical definition', function () {
    $expert = new class extends Expert
    {
        public function definition(): ExpertDefinition
        {
            return new ExpertDefinition(
                name: 'SEO',
                description: 'Search optimization methodology.',
                responsibilities: ['analyze search intent'],
                methodology: 'Evidence-first search analysis.',
                requiredContext: ['site'],
                capabilities: ['seo.analysis'],
            );
        }

        public function analyze(array $context): array
        {
            return ['keyword' => $context['keyword']];
        }
    };

    expect($expert->definition())
        ->toBeInstanceOf(ExpertDefinition::class)
        ->and($expert->name())->toBe('SEO')
        ->and($expert->description())->toBe('Search optimization methodology.')
        ->and($expert->responsibilities())->toBe(['analyze search intent'])
        ->and($expert->capabilities())->toBe(['seo.analysis'])
        ->and($expert->requiredContext())->toBe(['site'])
        ->and($expert->methodology())->toBe('Evidence-first search analysis.')
        ->and($expert->analyze(['keyword' => 'Laravel']))->toBe(['keyword' => 'Laravel']);
});

it('rejects incomplete expert definitions', function () {
    expect(fn () => new ExpertDefinition(
        name: '',
        description: 'Description.',
        responsibilities: ['analyze'],
        methodology: 'Method.',
        requiredContext: ['context'],
        capabilities: [],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ExpertDefinition(
        name: 'SEO',
        description: 'Description.',
        responsibilities: [],
        methodology: 'Method.',
        requiredContext: ['context'],
        capabilities: [],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ExpertDefinition(
        name: 'SEO',
        description: 'Description.',
        responsibilities: ['analyze'],
        methodology: '',
        requiredContext: ['context'],
        capabilities: [],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new ExpertDefinition(
        name: 'SEO',
        description: 'Description.',
        responsibilities: ['analyze'],
        methodology: 'Method.',
        requiredContext: [],
        capabilities: [],
    ))->toThrow(InvalidArgumentException::class);
});

it('does not grant authority through expert capability declarations', function () {
    $expert = new class extends Expert
    {
        public function definition(): ExpertDefinition
        {
            return new ExpertDefinition(
                name: 'SEO',
                description: 'Search optimization methodology.',
                responsibilities: ['analyze search intent'],
                methodology: 'Evidence-first search analysis.',
                requiredContext: ['site'],
                capabilities: ['seo.analysis'],
            );
        }

        public function analyze(array $context): array
        {
            return [];
        }
    };

    expect($expert->capabilities())->toBe(['seo.analysis'])
        ->and($expert->definition()->capabilities)->toBe(['seo.analysis'])
        ->and(method_exists($expert, 'authorize'))->toBeFalse();
});