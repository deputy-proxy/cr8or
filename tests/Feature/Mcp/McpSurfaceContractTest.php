<?php

use App\Capabilities\CapabilityRegistry;
use App\Contracts\CapabilityBoundaryTool;
use App\Contracts\Operation;
use App\Mcp\Servers\Cr8orServer;
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
use App\Models\Membership;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

it('registers every concrete MCP tool class with the CR8OR server', function (): void {
    $classes = collect(File::files(app_path('Mcp/Tools')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Tool.php'))
        ->map(fn ($file): string => 'App\\Mcp\\Tools\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class))
        ->filter(function (string $class): bool {
            $reflection = new ReflectionClass($class);

            return ! $reflection->isAbstract() && $reflection->isSubclassOf(Tool::class);
        })
        ->values()
        ->all();

    $actor = \App\Models\User::factory()->create();
    $organization = \App\Models\Organization::factory()->create();
    Membership::factory()->owner()->create(['user_id' => $actor->getKey(), 'organization_id' => $organization->getKey()]);

    Cr8orServer::actingAs($actor, 'api')->tools()->assertRegistered($classes);
});

it('routes every public MCP tool through an explicit authorization boundary family', function (): void {
    $classes = collect(File::files(app_path('Mcp/Tools')))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), 'Tool.php'))
        ->map(fn ($file): string => 'App\\Mcp\\Tools\\'.$file->getFilenameWithoutExtension())
        ->filter(fn (string $class): bool => class_exists($class))
        ->filter(function (string $class): bool {
            $reflection = new ReflectionClass($class);

            return ! $reflection->isAbstract() && $reflection->isSubclassOf(Tool::class);
        })
        ->values();

    $directAuthorizedExceptions = [
        \App\Mcp\Tools\CreateEnterpriseTool::class,
        \App\Mcp\Tools\GetCapabilityTool::class,
        \App\Mcp\Tools\ListCapabilitiesTool::class,
        \App\Mcp\Tools\RequestApprovalTool::class,
    ];
    $registry = app(CapabilityRegistry::class);

    foreach ($classes as $class) {
        expect(is_a($class, AuthorizedTool::class, true))
            ->toBeTrue("Public MCP Tool [{$class}] bypasses AuthorizedTool.");

        if (is_a($class, GovernedCapabilityTool::class, true)
            || is_a($class, DomainMutationTool::class, true)
            || is_a($class, DomainTransitionTool::class, true)
        ) {
            expect(is_a($class, CapabilityBoundaryTool::class, true))
                ->toBeTrue("Business Tool [{$class}] must implement the Capability boundary.");
            expect(fn () => $registry->forTool($class))
                ->not->toThrow(InvalidArgumentException::class);

            continue;
        }

        if (is_a($class, DiscoveryGetTool::class, true) || is_a($class, DiscoveryListTool::class, true)) {
            $method = (new ReflectionClass($class))->getMethod('modelClass');
            $method->setAccessible(true);
            $model = $method->invoke(app($class));
            expect(class_exists($model))->toBeTrue("Discovery Tool [{$class}] has no valid model scope target.");

            continue;
        }

        if (is_a($class, AgentAssignmentResourceTool::class, true) || is_a($class, AgentExecutionResourceTool::class, true)) {
            $method = (new ReflectionClass($class))->getMethod('operationClass');
            $method->setAccessible(true);
            $operation = $method->invoke(app($class));
            expect(class_exists($operation) && is_a($operation, Operation::class, true))
                ->toBeTrue("Resource Tool [{$class}] must delegate to a concrete Operation.");

            continue;
        }

        if (is_a($class, KnowledgeResourceTool::class, true) || is_a($class, MemoryResourceTool::class, true)) {
            $method = (new ReflectionClass($class))->getMethod('operationClass');
            $method->setAccessible(true);
            $operation = $method->invoke(app($class));
            expect(class_exists($operation) && is_a($operation, Operation::class, true))
                ->toBeTrue("Knowledge/Memory Tool [{$class}] must delegate to a concrete Operation.");

            continue;
        }

        expect(in_array($class, $directAuthorizedExceptions, true))
            ->toBeTrue("Public MCP Tool [{$class}] has no documented authorization boundary family.");
    }
});
