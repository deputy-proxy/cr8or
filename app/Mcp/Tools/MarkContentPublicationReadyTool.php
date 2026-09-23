<?php

namespace App\Mcp\Tools;

use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
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

#[Name('mark-content-publication-ready')]
#[Description('Mark approved content as publication-ready only with matching server-side approval.')]
class MarkContentPublicationReadyTool extends AuthorizedTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'content_item_id' => $schema->integer()->min(1)->required(),
            'agent_assignment_id' => $schema->integer()->min(1)->required(),
            'agent_execution_id' => $schema->integer()->min(1)->required(),
            'approval_request_id' => $schema->integer()->min(1)->required(),
        ];
    }

    public function handle(Request $request, McpCapabilityAuthorizer $authorization, ContentItemService $content): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'mcp.content.publication-ready', function () use ($request, $authorization, $content) {
            $validated = $request->validate([
                'content_item_id' => ['required', 'integer', 'min:1', 'exists:content_items,id'],
                'agent_assignment_id' => ['required', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['required', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['required', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            /** @var ContentItem $item */
            $item = ContentItem::query()->findOrFail($validated['content_item_id']);
            /** @var AgentAssignment $assignment */
            $assignment = AgentAssignment::query()->with('agentDescriptor')->findOrFail($validated['agent_assignment_id']);
            /** @var AgentExecution $execution */
            $execution = AgentExecution::query()->findOrFail($validated['agent_execution_id']);
            /** @var ApprovalRequest $approval */
            $approval = ApprovalRequest::query()->findOrFail($validated['approval_request_id']);

            $authorization->authorizeMutation(
                $actor,
                'content.publication_ready',
                $item->enterprise,
                $assignment->getKey(),
                $execution->getKey(),
                $approval->getKey(),
                ['content_item_id' => $item->getKey()],
                ['update', $item],
            );

            $item = $content->markPublicationReady($actor, $item, $approval, $assignment, $execution);

            return Response::structured(['success' => true, 'result' => ['id' => $item->id, 'status' => $item->status]]);
        });
    }
}
