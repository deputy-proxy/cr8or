<?php

use App\Capabilities\CapabilityRegistry;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

it('keeps every public MCP tool identifier canonical and unique', function (): void {
    $classes = collect(File::files(app_path('Mcp/Tools')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Tool.php'))
        ->map(fn ($file): string => 'App\\Mcp\\Tools\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class))
        ->filter(function (string $class): bool {
            $reflection = new ReflectionClass($class);

            return ! $reflection->isAbstract() && $reflection->isSubclassOf(Tool::class);
        })
        ->values();

    $catalogue = File::get(base_path('docs/mcp.md'));

    expect(preg_match('/^## Verified public Tool inventory\s+(.*?)^## Resources vs Tools/ms', $catalogue, $sectionMatch))
        ->toBe(1, 'docs/mcp.md must contain a bounded Verified public Tool inventory section.');

    preg_match_all(
        '/^\| `([^`]+)` \| `([^`]+)` \| ([^|]+) \| ([^|]+) \|$/m',
        $sectionMatch[1],
        $matches,
        PREG_SET_ORDER,
    );

    $documented = collect($matches)->map(fn (array $row): array => [
        'name' => $row[1],
        'class' => $row[2],
    ]);
    $documentedNames = $documented->pluck('name');
    $documentedClasses = $documented->pluck('class');
    $runtimeNamesByClass = $classes->mapWithKeys(fn (string $class): array => [
        class_basename($class) => app($class)->name(),
    ]);

    foreach ($runtimeNamesByClass as $name) {
        expect($name)
            ->toMatch('/^[a-z]+(?:-[a-z0-9]+)*$/')
            ->not->toContain('_')
            ->not->toContain('.');
    }

    $duplicateNames = $documentedNames->duplicates()->unique()->values()->all();
    expect($duplicateNames)->toBe(
        [],
        'Duplicate public Tool identifiers in the verified inventory: '.implode(', ', $duplicateNames),
    );

    $duplicateClasses = $documentedClasses->duplicates()->unique()->values()->all();
    expect($duplicateClasses)->toBe(
        [],
        'Duplicate Tool classes in the verified inventory: '.implode(', ', $duplicateClasses),
    );

    $missingNames = $runtimeNamesByClass->values()->diff($documentedNames)->values()->all();
    expect($missingNames)->toBe(
        [],
        'Public Tool identifiers missing from docs/mcp.md: '.implode(', ', $missingNames),
    );

    $staleNames = $documentedNames->diff($runtimeNamesByClass->values())->values()->all();
    expect($staleNames)->toBe(
        [],
        'Stale public Tool identifiers documented in docs/mcp.md: '.implode(', ', $staleNames),
    );

    $missingClasses = $runtimeNamesByClass->keys()->diff($documentedClasses)->values()->all();
    expect($missingClasses)->toBe(
        [],
        'Public Tool classes missing from docs/mcp.md: '.implode(', ', $missingClasses),
    );

    $staleClasses = $documentedClasses->diff($runtimeNamesByClass->keys())->values()->all();
    expect($staleClasses)->toBe(
        [],
        'Unknown Tool classes documented in docs/mcp.md: '.implode(', ', $staleClasses),
    );

    $classNameMismatches = $documented
        ->filter(fn (array $row): bool => $runtimeNamesByClass->get($row['class']) !== $row['name'])
        ->map(fn (array $row): string => $row['class'].' declares ['.$runtimeNamesByClass->get($row['class']).'] but docs list ['.$row['name'].']')
        ->values()
        ->all();
    expect($classNameMismatches)->toBe(
        [],
        'MCP inventory identifier/class mismatches: '.implode('; ', $classNameMismatches),
    );

    $names = $classes->mapWithKeys(fn (string $class): array => [$class => $runtimeNamesByClass->get(class_basename($class))]);
    expect($names->unique()->count())->toBe($names->count());

    foreach (app(CapabilityRegistry::class)->all() as $definition) {
        expect($names->get($definition->toolClass))->toBe($definition->tool);
    }
});
