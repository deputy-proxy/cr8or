<?php

namespace Tests\Support\Mcp;

use App\Mcp\Servers\Cr8orServer;

class AuthenticatedTestServer extends Cr8orServer
{
    protected array $tools = [
        ActorProbeTool::class,
    ];
}
