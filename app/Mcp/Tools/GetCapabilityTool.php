<?php

namespace App\Mcp\Tools;

use App\Models\AgentDescriptor;
use App\Models\ExpertDescriptor;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('get-capability')]
#[Description('Get a governed capability identifier exposed by the registered Agent and Expert runtimes.')]
class GetCapabilityTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->min(1)->max(255)->description('Capability identifier.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.discovery.get-capability', function () use ($request) {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $validated = $request->validate(['id' => ['required', 'string', 'min:1', 'max:255']]);
            $id = $validated['id'];
            $sources = ['agents' => [], 'experts' => []];

            foreach (AgentDescriptor::query()->where('enabled', true)->get() as $descriptor) {
                $runtime = app($descriptor->resolveRuntimeClass());
                if (in_array($id, $runtime->capabilities(), true)) {
                    $sources['agents'][] = $descriptor->slug;
                }
            }
            foreach (ExpertDescriptor::query()->where('enabled', true)->get() as $descriptor) {
                $runtime = app($descriptor->resolveRuntimeClass());
                if (in_array($id, $runtime->capabilities(), true)) {
                    $sources['experts'][] = $descriptor->slug;
                }
            }

            if ($sources['agents'] === [] && $sources['experts'] === []) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
            }

            sort($sources['agents']);
            sort($sources['experts']);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $id,
                    'name' => $id,
                    'agents' => $sources['agents'],
                    'experts' => $sources['experts'],
                ],
            ]);
        });
    }
}
