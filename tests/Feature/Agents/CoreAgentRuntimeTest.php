<?php

use App\Agents\Agent;
use App\Agents\CeoAgent;
use App\Agents\FinanceAgent;
use App\Agents\MarketingAgent;
use App\Agents\OperationsAgent;
use App\Agents\ProductAgent;
use App\Experts\BusinessAnalysisExpert;
use App\Experts\CopywritingExpert;
use App\Experts\Expert;
use App\Experts\ExpertDefinition;
use App\Experts\FinanceExpert;
use App\Experts\MarketingExpert;
use App\Experts\OperationsExpert;
use App\Experts\ProductExpert;
use App\Experts\SeoExpert;
use App\Experts\StrategyExpert;
use App\Models\AgentDescriptor;
use App\Models\ExpertDescriptor;
use InvalidArgumentException;
use ReflectionClass;

beforeEach(function () {
    $this->seed([
        \Database\Seeders\AgentDescriptorSeeder::class,
        \Database\Seeders\ExpertDescriptorSeeder::class,
    ]);
});

it('implements the five roadmap Agent roles with authoritative runtime metadata', function () {
    $agents = [
        'ceo' => CeoAgent::class,
        'marketing' => MarketingAgent::class,
        'finance' => FinanceAgent::class,
        'product' => ProductAgent::class,
        'operations' => OperationsAgent::class,
    ];

    foreach ($agents as $slug => $class) {
        $agent = app($class);

        $definition = $agent->definition();

        expect($agent)->toBeInstanceOf(Agent::class)
            ->and($definition->name)->toBe($agent->name())
            ->and($definition->description)->toBe($agent->description())
            ->and($definition->responsibilities)->toBe($agent->responsibilities())
            ->and($definition->instructions)->toBe($agent->instructions())
            ->and($definition->experts)->toBe($agent->experts())
            ->and($definition->requiredContext)->toBe($agent->requiredContext())
            ->and($definition->capabilities)->toBe($agent->capabilities())
            ->and($definition->decisionBoundaries)->toBe($agent->decisionBoundaries())
            ->and($definition->expectedOutputs)->toBe($agent->expectedOutputs())
            ->and($definition->capabilityMap)->toBe($agent->capabilityMap())
            ->and($definition->capabilityGaps)->toBe($agent->capabilityGaps())
            ->and($definition->approvalSensitiveCapabilities)->toBe($agent->approvalSensitiveCapabilities())
            ->and($agent->definitionVersion())->toMatch('/^[a-f0-9]{64}$/')
            ->and($agent->instructions())->not->toBeEmpty()
            ->and($agent->responsibilities())->not->toBeEmpty()
            ->and($agent->requiredContext())->not->toBeEmpty()
            ->and($agent->experts())->not->toBeEmpty()
            ->and(new ReflectionClass($class)->getParentClass()->getName())->toBe(Agent::class)
            ->and(method_exists($agent, 'execute'))->toBeTrue();

        expect(AgentDescriptor::query()->where('slug', $slug)->where('runtime_class', $class)->exists())->toBeTrue();
    }
});

it('registers the minimum domain Experts through the existing descriptor registry', function () {
    $experts = [
        'business-analysis' => BusinessAnalysisExpert::class,
        'copywriting' => CopywritingExpert::class,
        'marketing' => MarketingExpert::class,
        'finance' => FinanceExpert::class,
        'product' => ProductExpert::class,
        'seo' => SeoExpert::class,
        'strategy' => StrategyExpert::class,
        'operations' => OperationsExpert::class,
    ];

    foreach ($experts as $slug => $class) {
        $expert = app($class);
        $definition = $expert->definition();

        expect($expert)->toBeInstanceOf(Expert::class)
            ->and($definition)->toBeInstanceOf(ExpertDefinition::class)
            ->and($definition->name)->toBe($expert->name())
            ->and($definition->description)->toBe($expert->description())
            ->and($definition->responsibilities)->toBe($expert->responsibilities())
            ->and($definition->methodology)->toBe($expert->methodology())
            ->and($definition->requiredContext)->toBe($expert->requiredContext())
            ->and($definition->capabilities)->toBe($expert->capabilities())
            ->and($expert->name())->not->toBeEmpty()
            ->and($expert->description())->not->toBeEmpty()
            ->and($expert->responsibilities())->not->toBeEmpty()
            ->and($expert->capabilities())->not->toBeEmpty()
            ->and($expert->requiredContext())->not->toBeEmpty()
            ->and($expert->methodology())->not->toBeEmpty()
            ->and(method_exists($expert, 'analyze'))->toBeTrue()
            ->and(new ReflectionClass($class)->getParentClass()->getName())->toBe(Expert::class)
            ->and(ExpertDescriptor::query()->where('slug', $slug)->where('runtime_class', $class)->exists())->toBeTrue();
    }
});

it('coordinates Experts without granting them authority', function () {
    $agent = app(MarketingAgent::class);
    $expert = app(MarketingExpert::class);

    $coordination = $agent->coordinate(['enterprise' => ['id' => 1]], [$expert]);

    expect($coordination['agent'])->toBe('Marketing')
        ->and($coordination['results'][0]['expert'])->toBe('Marketing')
        ->and($coordination['results'][0]['result'])->toMatchArray([
            'focus' => 'marketing planning',
            'available_context' => ['enterprise'],
        ]);
});

it('rejects invalid runtime descriptor classes', function () {
    expect(fn () => AgentDescriptor::factory()->create([
        'runtime_class' => BusinessAnalysisExpert::class,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => ExpertDescriptor::factory()->create([
        'runtime_class' => CeoAgent::class,
    ]))->toThrow(InvalidArgumentException::class);
});

it('uses the canonical Agent execution entry point without changing coordination results', function () {
    $agent = app(MarketingAgent::class);
    $expert = app(MarketingExpert::class);
    expect($agent->execute(['enterprise' => ['id' => 1]], [$expert]))
        ->toBe($agent->coordinate(['enterprise' => ['id' => 1]], [$expert]));
});