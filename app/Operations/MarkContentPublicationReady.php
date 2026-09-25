<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\ContentItemService;

final class MarkContentPublicationReady implements Operation
{
    public function __construct(private readonly ContentItemService $content) {}

    public function execute(User $actor, array $input): ContentItem
    {
        return $this->content->markPublicationReady(
            $actor,
            $input['content_item'] instanceof ContentItem
                ? $input['content_item']
                : ContentItem::query()->findOrFail((int) $input['content_item_id']),
            $input['approval'] instanceof ApprovalRequest
                ? $input['approval']
                : ApprovalRequest::query()->findOrFail((int) $input['approval_request_id']),
            $input['assignment'] instanceof AgentAssignment
                ? $input['assignment']
                : AgentAssignment::query()->with('agentDescriptor')->findOrFail((int) $input['agent_assignment_id']),
            $input['execution'] instanceof AgentExecution
                ? $input['execution']
                : AgentExecution::query()->findOrFail((int) $input['agent_execution_id']),
        );
    }
}
