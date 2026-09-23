<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\EnterpriseContextResource;
use App\Mcp\Resources\KnowledgeContextResource;
use App\Mcp\Resources\StrategyContextResource;
use App\Mcp\Resources\WorkContextResource;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('CR8OR MCP Server')]
#[Version('0.1.0')]
#[Instructions('Provides controlled access to CR8OR capabilities through authorized enterprise resources.')]
class Cr8orServer extends Server
{
    protected array $tools = [];

    protected array $resources = [
        EnterpriseContextResource::class,
        StrategyContextResource::class,
        KnowledgeContextResource::class,
        WorkContextResource::class,
    ];

    protected array $prompts = [];
}
