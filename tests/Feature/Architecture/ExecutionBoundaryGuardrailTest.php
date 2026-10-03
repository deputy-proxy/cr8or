<?php

use Illuminate\Support\Facades\File;

it('keeps non-MCP execution layers free of direct business Operation invocation', function (): void {
    $paths = [
        app_path('Agents'),
        app_path('Experts'),
        app_path('Services/AgentExecutionService.php'),
        app_path('Services/AgentDelegationService.php'),
        app_path('Services/ExpertInvocationService.php'),
        app_path('Services/WorkflowExecutionService.php'),
        app_path('Services/InteractiveContinuationService.php'),
        app_path('Http/Controllers/CommandWebhookController.php'),
    ];

    $patterns = [
        '/operationForTool\\(/',
        '/->operation\\([^)]*\\)->execute/',
        '/app\\([^)]*Operation/',
    ];

    foreach ($paths as $path) {
        $files = is_dir($path)
            ? collect(File::allFiles($path))
            : collect([new SplFileInfo($path)]);

        foreach ($files as $file) {
            $source = File::get($file->getPathname());

            foreach ($patterns as $pattern) {
                expect($source)->not->toMatch($pattern, $file->getPathname());
            }
        }
    }
});

it('keeps exactly one authoritative Capability registry implementation', function (): void {
    $files = collect(File::allFiles(app_path()))
        ->filter(fn (SplFileInfo $file): bool => $file->getFilename() === 'CapabilityRegistry.php')
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->values();

    expect($files)->toHaveCount(1);
});

it('keeps lifecycle MCP Tool names outside the business namespace', function (): void {
    $registry = app(\App\Capabilities\CapabilityRegistry::class);

    foreach ($registry->all() as $definition) {
        if ($definition->category === 'lifecycle') {
            expect($definition->tool)->toStartWith('mcp_');
        } else {
            expect($definition->tool)->not->toStartWith('mcp_');
        }
    }
});