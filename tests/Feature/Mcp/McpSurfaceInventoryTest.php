<?php

use App\Capabilities\CapabilityRegistry;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

it('keeps the committed MCP audit inventory synchronized with the concrete tool surface', function (): void {
    $audit = File::get(base_path('docs/architecture/operation-capability-mcp-audit.md'));

    $classes = collect(File::files(app_path('Mcp/Tools')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Tool.php'))
        ->map(fn ($file): string => 'App\\Mcp\\Tools\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class))
        ->filter(function (string $class): bool {
            $reflection = new ReflectionClass($class);

            return ! $reflection->isAbstract() && $reflection->isSubclassOf(Tool::class);
        })
        ->values();

    foreach ($classes as $class) {
        expect($audit)->toContain(class_basename($class));
    }

    $registry = app(CapabilityRegistry::class);

    foreach ($registry->all() as $definition) {
        expect($audit)->toContain(class_basename($definition->operation))
            ->and($audit)->toContain($definition->tool)
            ->and($audit)->toContain(class_basename($definition->toolClass));
    }

    foreach (File::files(app_path('Operations')) as $file) {
        expect($audit)->toContain($file->getFilenameWithoutExtension());
    }
});