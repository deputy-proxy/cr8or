<?php

namespace Tests\Support\Mcp;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('actor-probe')]
class ActorProbeTool extends Tool
{
    public function handle(Request $request): Response
    {
        return Response::text((string) $request->user()?->getAuthIdentifier());
    }
}
