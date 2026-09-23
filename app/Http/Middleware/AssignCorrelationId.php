<?php

namespace App\Http\Middleware;

use App\Services\ExecutionCorrelationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlation = app(ExecutionCorrelationService::class);
        $correlationId = $correlation->resolve($request->header(ExecutionCorrelationService::HEADER));
        $request->attributes->set('cr8or.correlation_id', $correlationId);
        Log::withContext(['correlation_id' => $correlationId]);
        $response = $next($request);
        $response->headers->set(ExecutionCorrelationService::HEADER, $correlationId);

        return $response;
    }
}
