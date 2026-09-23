<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Tests\Support\Mcp\ActorProbeTool;
use Tests\Support\Mcp\AuthenticatedTestServer;

it('registers the protected MCP endpoint and OAuth discovery routes', function () {
    expect(Route::has('mcp.oauth.authorization-server'))->toBeTrue()
        ->and(Route::has('mcp.oauth.protected-resource.nested'))->toBeTrue();

    $route = Route::getRoutes()->getByName('mcp.oauth.protected-resource.nested');

    expect($route?->uri())->toBe('.well-known/oauth-protected-resource/{path}');

    $mcpRoute = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === 'mcp');
    expect($mcpRoute)->not->toBeNull();
});

it('preserves a supplied correlation id on the protected MCP boundary', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'server/discover',
    ], [
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'server/discover',
        'X-Correlation-ID' => 'mcp-test-123',
    ]);

    $response->assertUnauthorized()->assertHeader('X-Correlation-ID', 'mcp-test-123');
});

it('requires authentication before the MCP endpoint is reachable', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'server/discover',
        'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ],
        ],
    ], [
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'server/discover',
    ]);

    $response->assertUnauthorized();
});

it('rejects invalid bearer authentication', function () {
    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'server/discover',
        'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ],
        ],
    ], [
        'Accept' => 'application/json, text/event-stream',
        'Authorization' => 'Bearer invalid-token',
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'server/discover',
    ]);

    $response->assertUnauthorized();
});

it('serves the modern MCP discovery request for an authenticated actor', function () {
    $user = User::factory()->create();

    Passport::actingAs($user, ['mcp:use']);

    $response = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'server/discover',
        'params' => [
            '_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ],
        ],
    ], [
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'server/discover',
    ]);

    $response->assertOk()->assertJsonPath('result.supportedVersions', ['2026-07-28']);
    $response->assertJsonFragment(['io.modelcontextprotocol/serverInfo' => ['name' => 'CR8OR MCP Server', 'version' => '0.1.0']]);
});

it('resolves the authenticated Laravel user inside MCP handlers without exposing business state', function () {
    $user = User::factory()->create();

    AuthenticatedTestServer::actingAs($user, 'api')
        ->tool(ActorProbeTool::class)
        ->assertOk()
        ->assertSee((string) $user->getAuthIdentifier());
});

it('does not expose business capabilities from authentication alone', function () {
    $user = User::factory()->create();
    Passport::actingAs($user, ['mcp:use']);

    $headers = [
        'Accept' => 'application/json, text/event-stream',
        'MCP-Protocol-Version' => '2026-07-28',
    ];

    $meta = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    $tools = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
        'params' => ['_meta' => $meta],
    ], [...$headers, 'Mcp-Method' => 'tools/list']);

    $resources = $this->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'resources/list',
        'params' => ['_meta' => $meta],
    ], [...$headers, 'Mcp-Method' => 'resources/list']);

    $tools->assertOk()->assertJsonPath('result.tools', []);
    $resources->assertOk()->assertJsonPath('result.resources', []);
});
