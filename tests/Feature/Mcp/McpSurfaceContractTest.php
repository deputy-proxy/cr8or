<?php

use App\Mcp\Servers\Cr8orServer;
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
