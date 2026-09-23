<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('CR8OR MCP Server')]
#[Version('0.1.0')]
#[Instructions('Provides controlled access to CR8OR capabilities. Business resources and tools are registered in later implementation phases.')]
class Cr8orServer extends Server
{
    protected array $tools = [];

    protected array $resources = [];

    protected array $prompts = [];
}