<?php

use App\Agents\Agent;
use App\Agents\CeoAgent;
use App\Agents\FinanceAgent;
use App\Agents\MarketingAgent;
use App\Agents\OperationsAgent;
use App\Agents\ProductAgent;
use App\Experts\BusinessAnalysisExpert;
use App\Experts\Expert;
use App\Experts\FinanceExpert;
use App\Experts\MarketingExpert;
use App\Experts\OperationsExpert;
use App\Experts\ProductExpert;
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

        expect($agent)->toBeInstanceOf(Agent::class)
            ->and($agent->name())->not->toBeEmpty()
            ->and($agent->description())->not->toBeEmpty()
            ->and($agent->responsibilities())->not->toBeEmpty()
            ->and($agent->capabilities())->not->toBeEmpty()
            ->and($agent->requiredContext())->not->toBeEmpty()
            ->and(new ReflectionClass($class)->getParentClass()->getName())->toBe(Agent::class);

        expect(AgentDescriptor::query()->where('slug', $slug)->where('runtime_class', $class)->exists())->toBeTrue();
    }
});

it('registers the minimum domain Experts through the existing descriptor registry', function () {
    $experts = [
        'business-analysis' => BusinessAnalysisExpert::class,
        'marketing' => MarketingExpert::class,
        'finance' => FinanceExpert::class,
        'product' => ProductExpert::class,
        'operations' => OperationsExpert::class,
    ];

    foreach ($experts as $slug => $class) {
        $expert = app($class);

        expect($expert)->toBeInstanceOf(Expert::class)
            ->and($expert->name())->not->toBeEmpty()
            ->and($expert->description())->not->toBeEmpty()
            ->and($expert->responsibilities())->not->toBeEmpty()
            ->and($expert->capabilities())->not->toBeEmpty()
            ->and($expert->requiredContext())->not->toBeEmpty()
            ->and($expert->methodology())->not->toBeEmpty()
            ->and(ExpertDescriptor::query()->where('slug', $slug)->where('runtime_class', $class)->exists())->toBeTrue();
    }
});

it('coordinates Experts without granting them authority', function () {
    $agent = app(MarketingAgent::class);
    $expert = app(MarketingExpert::class);

    expect($agent->coordinate(['enterprise' => ['id' => 1]], [$expert]))
        ->toMatchArray([
            'agent' => 'Marketing',
            'results' => [[
                'expert' => 'Marketing',
                'result' => [
                    'focus' => 'marketing planning',
                    'available_context' => ['enterprise'],
                ],
            ]]]);
});

it('rejects invalid runtime descriptor classes', function () {
    expect(fn () => AgentDescriptor::factory()->create([
        'runtime_class' => BusinessAnalysisExpert::class,
    ]))->toThrow(InvalidArgumentException::class);

    expect(fn () => ExpertDescriptor::factory()->create([
        'runtime_class' => CeoAgent::class,
    ]))->toThrow(InvalidArgumentException::class);
});
