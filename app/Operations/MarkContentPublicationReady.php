<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\CapabilityExecutionContext;
use App\Enums\CapabilityExecutionMode;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\ApprovalRequestService;
use App\Services\ContentItemService;
use Illuminate\Auth\Access\AuthorizationException;

final class MarkContentPublicationReady implements Operation
{
    public function __construct(private readonly ContentItemService $content) {}

    public function execute(User $actor, array $input): ContentItem
    {
        $item = ($input['content_item'] ?? null) instanceof ContentItem ? $input['content_item'] : ContentItem::query()->findOrFail((int) $input['content_item_id']);
        $approval = ($input['approval'] ?? null) instanceof ApprovalRequest ? $input['approval'] : ApprovalRequest::query()->findOrFail((int) $input['approval_request_id']);
        $context = $input['execution_context'] ?? null;
        if ($context instanceof CapabilityExecutionContext && $context->mode === CapabilityExecutionMode::WORKFLOW) {
            $execution = $context->workflowExecution;
            if ($execution === null || ! app(ApprovalRequestService::class)->matchesWorkflow($approval, $actor, $execution, 'marketing.content.publication-ready', ['content_item_id' => $item->getKey()], allowConsumed: true)) {
                throw new AuthorizationException('The Workflow approval does not match this content item and execution.');
            }

            return $this->content->markPublicationReady($actor, $item, $approval, workflowExecution: $execution);
        }

        return $this->content->markPublicationReady($actor, $item, $approval,
            ($input['assignment'] ?? null) instanceof AgentAssignment ? $input['assignment'] : AgentAssignment::query()->with('agentDescriptor')->findOrFail((int) $input['agent_assignment_id']),
            ($input['execution'] ?? null) instanceof AgentExecution ? $input['execution'] : AgentExecution::query()->findOrFail((int) $input['agent_execution_id']),
        );
    }
}
