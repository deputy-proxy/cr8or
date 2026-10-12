<?php

use App\Mcp\Tools\AgentAssignmentResourceTool;
use App\Mcp\Tools\AgentExecutionResourceTool;
use App\Mcp\Tools\AuthorizedTool;
use App\Mcp\Tools\DiscoveryGetTool;
use App\Mcp\Tools\DiscoveryListTool;
use App\Mcp\Tools\DomainMutationTool;
use App\Mcp\Tools\DomainTransitionTool;
use App\Mcp\Tools\GovernedCapabilityTool;
use App\Mcp\Tools\KnowledgeResourceTool;
use App\Mcp\Tools\MemoryResourceTool;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

it('keeps the authorization matrix in exact sync with all concrete public MCP tools', function (): void {
    $classes = collect(File::files(app_path('Mcp/Tools')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Tool.php'))
        ->map(fn ($file): string => 'App\\Mcp\\Tools\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class))
        ->filter(function (string $class): bool {
            $reflection = new ReflectionClass($class);

            return ! $reflection->isAbstract() && $reflection->isSubclassOf(Tool::class);
        })
        ->values();

    $families = [
        GovernedCapabilityTool::class => 'GovernedCapabilityTool',
        DomainMutationTool::class => 'DomainMutationTool',
        DomainTransitionTool::class => 'DomainTransitionTool',
        DiscoveryGetTool::class => 'DiscoveryGetTool',
        DiscoveryListTool::class => 'DiscoveryListTool',
        AgentAssignmentResourceTool::class => 'AgentAssignmentResourceTool',
        AgentExecutionResourceTool::class => 'AgentExecutionResourceTool',
        KnowledgeResourceTool::class => 'KnowledgeResourceTool',
        MemoryResourceTool::class => 'MemoryResourceTool',
    ];
    $directExceptions = [
        \App\Mcp\Tools\CreateEnterpriseTool::class,
        \App\Mcp\Tools\GetCapabilityTool::class,
        \App\Mcp\Tools\ListCapabilitiesTool::class,
        \App\Mcp\Tools\RequestApprovalTool::class,
    ];

    $runtime = $classes->mapWithKeys(function (string $class) use ($families, $directExceptions): array {
        $family = collect($families)->first(
            fn (string $label, string $base): bool => is_a($class, $base, true),
        );

        return [$class => [
            'tool' => app($class)->name(),
            'class' => class_basename($class),
            'family' => $family ?? (in_array($class, $directExceptions, true) ? 'Explicit direct-tool boundary' : 'unclassified'),
            'authorized' => is_a($class, AuthorizedTool::class, true),
        ]];
    });

    $document = File::get(base_path('docs/mcp-authorization.md'));
    expect(preg_match('/^## Concrete public tool mapping\\s+(.*?)^## Cross-cutting invariants/ms', $document, $section))
        ->toBe(1, 'Authorization matrix must have a bounded concrete tool mapping section.');

    preg_match_all('/^\\| ([^|]+) \\| `([^`]+)` \\| `([^`]+)` \\| ([^|]+) \\| ([^|]+) \\| ([^|]+) \\|$/m', $section[1], $matches, PREG_SET_ORDER);
    $documented = collect($matches)->mapWithKeys(fn (array $row): array => [$row[2] => [
        'tool' => trim($row[1]),
        'class' => trim($row[2]),
        'family' => trim($row[3], ' `\\t\\n\\r\\0\\x0B'),
    ]]);

    expect($documented->count())->toBe($classes->count(), 'Authorization matrix must contain one row per concrete public Tool.');

    foreach ($runtime as $class => $entry) {
        expect($entry['authorized'])->toBeTrue("Public Tool [{$class}] must inherit AuthorizedTool.")
            ->and($entry['family'])->not->toBe('unclassified', "Public Tool [{$class}] needs an explicit authorization boundary family.")
            ->and($documented->has($entry['class']))->toBeTrue("Public Tool [{$class}] is missing from docs/mcp-authorization.md.")
            ->and($documented[$entry['class']]['tool'])->toBe($entry['tool'])
            ->and($documented[$entry['class']]['family'])->toBe($entry['family']);
    }

    $staleClasses = $documented->keys()->diff($runtime->map(fn (array $entry): string => $entry['class']))->values()->all();
    expect($staleClasses)->toBe([], 'Unknown Tool classes in authorization matrix: '.implode(', ', $staleClasses));
});
