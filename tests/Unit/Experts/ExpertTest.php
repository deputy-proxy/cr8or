<?php

use App\Experts\Expert;

it('exposes authoritative expert methodology metadata without authority', function () {
    $expert = new class extends Expert
    {
        public function name(): string { return 'SEO'; }
        public function description(): string { return 'Search optimization methodology.'; }
        public function responsibilities(): array { return ['analyze search intent']; }
        public function capabilities(): array { return ['seo.analysis']; }
        public function requiredContext(): array { return ['site']; }
        public function methodology(): string { return 'Evidence-first search analysis.'; }
        public function analyze(array $context): array { return ['keyword' => $context['keyword']]; }
    };

    expect($expert->name())->toBe('SEO')
        ->and($expert->description())->toBe('Search optimization methodology.')
        ->and($expert->responsibilities())->toBe(['analyze search intent'])
        ->and($expert->capabilities())->toBe(['seo.analysis'])
        ->and($expert->requiredContext())->toBe(['site'])
        ->and($expert->methodology())->toBe('Evidence-first search analysis.')
        ->and($expert->analyze(['keyword' => 'Laravel']))->toBe(['keyword' => 'Laravel']);
});
