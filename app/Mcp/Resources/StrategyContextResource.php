<?php

namespace App\Mcp\Resources;

use App\Models\User;
use App\Services\McpContextAssembler;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

#[Description('Authorized strategy context for the requested enterprise.')]
class StrategyContextResource extends Resource implements HasUriTemplate
{
    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('cr8or://enterprises/{enterprise}/strategy');
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            return Response::json(app(McpContextAssembler::class)->strategy(
                $user,
                $this->enterpriseId($request->get('enterprise')),
            ));
        }

        throw new AuthorizationException('Authentication is required to read MCP resources.');
    }

    private function enterpriseId(mixed $value): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new \InvalidArgumentException('The enterprise resource identifier must be a positive integer.');
    }
}