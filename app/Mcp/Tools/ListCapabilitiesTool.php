<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\AgentDescriptor;
use App\Models\ExpertDescriptor;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('list-capabilities')]
#[Description('Discover the governed capability identifiers exposed by the registered Agent and Expert runtimes.')]
class ListCapabilitiesTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->min(1)->max(255)->description('Optional capability search.'),
            'per_page' => $schema->integer()->min(1)->max(50)->description('Results per page.')->default(20),
            'page' => $schema->integer()->min(1)->description('1-based page number.')->default(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.discovery.list-capabilities', function () use ($request, $registry) {
            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new \Illuminate\Auth\AuthenticationException;
            }

            $validated = $request->validate([
                'search' => ['nullable', 'string', 'min:1', 'max:255'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
                'page' => ['nullable', 'integer', 'min:1'],
            ]);

            $capabilities = [];
            foreach (AgentDescriptor::query()->where('enabled', true)->get() as $descriptor) {
                $runtime = app($descriptor->resolveRuntimeClass());
                foreach ($runtime->capabilities() as $capability) {
                    $definition = $registry->resolve($capability);
                    $capabilities[$capability]['id'] = $capability;
                    $capabilities[$capability]['name'] = $capability;
                    $capabilities[$capability]['operation'] = $definition->operation;
                    $capabilities[$capability]['tool'] = $definition->tool;
                    $capabilities[$capability]['agents'][] = $descriptor->slug;
                }
            }
            foreach (ExpertDescriptor::query()->where('enabled', true)->get() as $descriptor) {
                $runtime = app($descriptor->resolveRuntimeClass());
                foreach ($runtime->capabilities() as $capability) {
                    $definition = $registry->resolve($capability);
                    $capabilities[$capability]['id'] = $capability;
                    $capabilities[$capability]['name'] = $capability;
                    $capabilities[$capability]['operation'] = $definition->operation;
                    $capabilities[$capability]['tool'] = $definition->tool;
                    $capabilities[$capability]['experts'][] = $descriptor->slug;
                }
            }

            ksort($capabilities);
            $items = array_values(array_map(function (array $item): array {
                $item['agents'] ??= [];
                $item['experts'] ??= [];
                sort($item['agents']);
                sort($item['experts']);

                return $item;
            }, $capabilities));

            if (isset($validated['search'])) {
                $needle = mb_strtolower($validated['search']);
                $items = array_values(array_filter($items, fn (array $item): bool => str_contains(mb_strtolower($item['id']), $needle)));
            }

            $perPage = (int) ($validated['per_page'] ?? 20);
            $page = (int) ($validated['page'] ?? 1);
            $total = count($items);
            $items = array_slice($items, ($page - 1) * $perPage, $perPage);

            return Response::structured([
                'success' => true,
                'result' => [
                    'items' => $items,
                    'pagination' => [
                        'page' => $page,
                        'per_page' => $perPage,
                        'total' => $total,
                        'last_page' => max(1, (int) ceil($total / $perPage)),
                    ],
                ],
            ]);
        });
    }
}