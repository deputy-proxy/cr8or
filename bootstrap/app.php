<?php

use App\Services\ExecutionCorrelationService;
use App\Services\FailureTranslator;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: static function (): void {
            require base_path('routes/ai.php');
            require base_path('routes/integrations.php');
            require base_path('routes/commands.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $failure = app(FailureTranslator::class)->translate(
                $exception,
                correlationId: app(ExecutionCorrelationService::class)->resolve(),
            );

            $status = match ($failure->code) {
                'authentication.required' => 401,
                'authorization.denied' => 403,
                'validation.failed' => 422,
                'resource.not_found' => 404,
                'conflict.detected' => 409,
                'external.rate_limited', 'provider.rate_limited' => 429,
                'external.unavailable', 'provider.unavailable', 'external.timeout', 'provider.timeout' => 503,
                default => 500,
            };

            return response()->json($failure->toArray(), $status);
        });
    })->create();