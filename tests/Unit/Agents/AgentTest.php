<?php

use App\Agents\Agent;
use App\Experts\Expert;

it('exposes authoritative runtime metadata and coordinates experts', function () {
    $agent = new class extends Agent
    {
        public function definition(): \App\Agents\AgentDefinition
        {
            return new \App\Agents\AgentDefinition(
                name: 'Marketing', description: 'Coordinates marketing work.',
                responsibilities: ['route requests', 'coordinate experts'],
                instructions: 'Coordinate marketing work within authorized context.',
                experts: ['marketing'], requiredContext: ['enterprise'],
                capabilities: ['marketing.plan'],
            );
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
