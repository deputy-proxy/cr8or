<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Data\CapabilityExecutionContext;
use App\Enums\CapabilityExecutionMode;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\User;
use App\Services\ApprovalRequestService;
use App\Services\ContentItemService;
use Illuminate\Auth\Access\AuthorizationException;

final class ApproveContentItem implements Operation
{
    public function __construct(private readonly ContentItemService $content) {}

    public function execute(User $actor, array $input): ContentItem
    {
        $item = ($input['content_item'] ?? null) instanceof ContentItem
            ? $input['content_item']
            : ContentItem::query()->findOrFail((int) $input['content_item_id']);

        $context = $input['execution_context'] ?? null;
        if ($context instanceof CapabilityExecutionContext && $context->mode === CapabilityExecutionMode::WORKFLOW) {
            $execution = $context->workflowExecution;
            $approval = ($input['approval'] ?? null) instanceof ApprovalRequest
                ? $input['approval']
                : ApprovalRequest::query()->findOrFail((int) $input['approval_request_id']);

            if ($execution === null || ! app(ApprovalRequestService::class)->matchesWorkflow(
                $approval,
                $actor,
                $execution,
                'marketing.content.approve',
                ['content_item_id' => $item->getKey()],
                allowConsumed: true,
            )) {
                throw new AuthorizationException('The Workflow content approval does not match this content item and execution.');
            }
        }

        return $this->content->approve($actor, $item);
    }
}
