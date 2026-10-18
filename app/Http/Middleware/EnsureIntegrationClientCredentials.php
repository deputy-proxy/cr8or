<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureIntegrationClientCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('api');
        $client = method_exists($guard, 'client') ? $guard->client() : null;

        if ($request->user('api') !== null || $client === null || ! $client->hasGrantType('client_credentials')) {
            throw new AuthenticationException('A client-credentials integration token is required.');
        }

        $request->attributes->set('integration_oauth_client_id', (string) $client->getKey());

        return $next($request);
    }
}
