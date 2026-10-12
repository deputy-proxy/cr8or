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

    $names = $classes->mapWithKeys(function (string $class) use ($catalogue): array {
        $name = app($class)->name();

        expect($name)
            ->toMatch('/^[a-z]+(?:-[a-z0-9]+)*$/')
            ->not->toContain('_')
            ->not->toContain('.');

        expect($catalogue)->toContain("| `{$name}` | `".class_basename($class).'` |');

        return [$class => $name];
    });

    expect($names->unique()->count())->toBe($names->count());

    foreach (app(CapabilityRegistry::class)->all() as $definition) {
        expect($names->get($definition->toolClass))->toBe($definition->tool);
    }
});
