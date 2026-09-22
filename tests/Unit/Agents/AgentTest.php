<?php

use App\Agents\Agent;
use App\Experts\Expert;

it('exposes authoritative runtime metadata and coordinates experts', function () {
    $agent = new class extends Agent
    {
        public function name(): string
        {
            return 'Marketing';
        }

        public function description(): string
        {
            return 'Coordinates marketing work.';
        }

        public function responsibilities(): array
        {
            return ['route requests', 'coordinate experts'];
        }

        public function capabilities(): array
        {
            return ['marketing.plan'];
        }

        public function requiredContext(): array
        {
            return ['enterprise'];
        }
    };

    $expert = new class extends Expert
    {
        public function name(): string
        {
            return 'Copywriting';
        }

        public function description(): string
        {
            return 'Creates copy.';
        }

        public function responsibilities(): array
        {
            return ['write copy'];
        }

        public function capabilities(): array
        {
            return ['copy.draft'];
        }

        public function requiredContext(): array
        {
            return ['brand'];
        }

        public function methodology(): string
        {
            return 'Audience-first copywriting.';
        }

        public function analyze(array $context): array
        {
            return ['headline' => $context['topic']];
        }
    };

    expect($agent->name())->toBe('Marketing')
        ->and($agent->description())->toBe('Coordinates marketing work.')
        ->and($agent->responsibilities())->toBe(['route requests', 'coordinate experts'])
        ->and($agent->capabilities())->toBe(['marketing.plan'])
        ->and($agent->requiredContext())->toBe(['enterprise'])
        ->and($agent->coordinate(['topic' => 'CR8OR'], [$expert]))->toBe([
            'agent' => 'Marketing',
            'results' => [
                ['expert' => 'Copywriting', 'result' => ['headline' => 'CR8OR']],
            ],
        ]);
});
