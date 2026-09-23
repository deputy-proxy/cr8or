<?php

namespace App\Mcp\Tools;

use App\Models\ContentItem;
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

#[Name('update-content-item')]
#[Description('Revise draft or in-review content through the authorized CR8OR content capability.')]
class UpdateContentItemTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'content_item_id' => $schema->integer()->min(1)->required(),
            'title' => $schema->string()->min(1)->max(255),
            'body' => $schema->string()->max(100000),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, ContentItemService $content): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.content.update', function () use ($request, $authorization, $content) {
            $validated = $request->validate([
                'content_item_id' => ['required', 'integer', 'min:1', 'exists:content_items,id'],
                'title' => ['sometimes', 'string', 'min:1', 'max:255'],
                'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var ContentItem $item */
            $item = ContentItem::query()->findOrFail($validated['content_item_id']);
            $authorization->authorizeMutation(
                $actor,
                'content.update',
                $item->enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['content_item_id' => $item->getKey()],
                ['update', $item],
            );

            $attributes = array_intersect_key($validated, array_flip(['title', 'body']));
            if ($attributes === []) {
                throw new \LogicException('At least one mutable content field is required.');
            }

            $item = $content->update($actor, $item, $attributes);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $item->getKey(),
                    'enterprise_id' => $item->enterprise_id,
                    'title' => $item->title,
                    'body' => $item->body,
                    'status' => $item->status,
                ],
            ]);
        });
    }
}
