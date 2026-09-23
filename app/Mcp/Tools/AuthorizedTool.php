<?php

namespace App\Mcp\Tools;

use App\AI\Contracts\ExecutionError;
use App\Models\User;
use App\Services\ExecutionCorrelationService;
use Closure;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

abstract class AuthorizedTool extends Tool
{
    public function shouldRegister(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->memberships()->exists();
    }

    protected function executeWithErrors(Request $request, string $operation, Closure $callback): Response|ResponseFactory
    {
        $correlation = app(ExecutionCorrelationService::class);
        $correlationId = $correlation->forMcp($request);

        try {
            return $callback($correlationId);
        } catch (Throwable $exception) {
            $error = ExecutionError::from($exception);
            $correlation->logFailure($operation, $correlationId, $error, ['actor_id' => $request->user()?->getAuthIdentifier()]);

            return Response::error(json_encode($error->toArray($correlationId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
    }
}
