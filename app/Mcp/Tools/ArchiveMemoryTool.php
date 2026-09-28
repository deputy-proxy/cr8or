<?php

namespace App\Mcp\Tools;

use App\Operations\ArchiveMemory;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('archive-memory')]
#[Description('Archive a semantic Memory record while preserving its history.')]
class ArchiveMemoryTool extends MemoryResourceTool
{
    protected function operationClass(): string
    {
        return ArchiveMemory::class;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'memory_id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeOperation($request, $request->validate([
            'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
            'memory_id' => ['required', 'integer', 'min:1'],
        ]));
    }
}