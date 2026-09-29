<?php

namespace App\Mcp;

use App\AI\Contracts\FailureProvenance;
use App\Services\ExecutionCorrelationService;
use App\Services\FailureTranslator;
use Closure;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Throwable;

final class McpFailureResponder
{
    public function execute(Request $request, string $operation, Closure $callback): Response|ResponseFactory
    {
        $correlation = app(ExecutionCorrelationService::class);
        $correlationId = $correlation->forMcp($request);

        try {
            return $callback($correlationId);
        } catch (Throwable $exception) {
            $failure = app(FailureTranslator::class)->translate(
                $exception,
                correlationId: $correlationId,
                provenance: new FailureProvenance(operation: $operation),
            );

            $correlation->logFailure($operation, $correlationId, $failure, [
                'actor_id' => $request->user()?->getAuthIdentifier(),
            ]);

            return Response::error(json_encode(
                $failure->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));
        }
    }
}