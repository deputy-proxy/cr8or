<?php

namespace App\Mcp\Tools;

use App\Mcp\McpFailureResponder;
use App\Models\User;
use Closure;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class AuthorizedTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->memberships()->exists();
    }

    protected function executeWithErrors(Request $request, string $operation, Closure $callback): Response|ResponseFactory
    {
        return app(McpFailureResponder::class)->execute($request, $operation, $callback);
    }
}