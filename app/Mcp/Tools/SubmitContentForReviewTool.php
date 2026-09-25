<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('submit-content-for-review')]
#[Description('Move draft content into the governed review state.')]
class SubmitContentForReviewTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'content_item_id' => $schema->integer()->min(1)->required(),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.marketing.content.review', function () use ($request, $authorization, $registry) {
            $validated = $request->validate([
                'content_item_id' => ['required', 'integer', 'min:1', 'exists:content_items,id'],
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
                $this->capability($registry),
                $item->enterprise,
                $validated['agent_assignment_id'] ?? null,
                $validated['agent_execution_id'] ?? null,
                $validated['approval_request_id'] ?? null,
                ['content_item_id' => $item->getKey()],
                ['update', $item],
            );

            $item = $this->executeCapability($registry, $actor, ['content_item' => $item, ...$validated]);

            return Response::structured(['success' => true, 'result' => ['id' => $item->id, 'status' => $item->status]]);
        });
    }
}