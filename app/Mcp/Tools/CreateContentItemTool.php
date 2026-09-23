<?php

namespace App\Mcp\Tools;

use App\Models\ContentItem;
use App\Models\Enterprise;
use App\Models\User;
use App\Services\ContentItemService;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('create-content-item')]
#[Description('Create draft content through the authorized CR8OR content capability.')]
class CreateContentItemTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'enterprise_id' => $schema->integer()->min(1)->required(),
            'campaign_id' => $schema->integer()->min(1)->required(),
            'content_series_id' => $schema->integer()->min(1),
            'channel_id' => $schema->integer()->min(1),
            'audience_id' => $schema->integer()->min(1),
            'title' => $schema->string()->min(1)->max(255)->required(),
            'body' => $schema->string()->max(100000),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, ContentItemService $content): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.content.create', function () use ($request, $authorization, $content) {
            $validated = $request->validate([
                'enterprise_id' => ['required', 'integer', 'min:1', 'exists:enterprises,id'],
                'campaign_id' => ['required', 'integer', 'min:1', 'exists:campaigns,id'],
                'content_series_id' => ['nullable', 'integer', 'min:1', 'exists:content_series,id'],
                'channel_id' => ['nullable', 'integer', 'min:1', 'exists:channels,id'],
                'audience_id' => ['nullable', 'integer', 'min:1', 'exists:audiences,id'],
                'title' => ['required', 'string', 'min:1', 'max:255'],
                'body' => ['nullable', 'string', 'max:100000'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var Enterprise $enterprise */
            $enterprise = Enterprise::query()->findOrFail($validated['enterprise_id']);

            $authorization->authorizeMutation(
                $actor,
                'content.create',
                $enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['enterprise_id' => $enterprise->getKey()],
                ['create', [ContentItem::class, $enterprise]],
            );

            $item = $content->create($actor, $enterprise, $validated);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $item->getKey(),
                    'enterprise_id' => $item->enterprise_id,
                    'campaign_id' => $item->campaign_id,
                    'title' => $item->title,
                    'body' => $item->body,
                    'status' => $item->status,
                ],
            ]);
        });
    }
}
