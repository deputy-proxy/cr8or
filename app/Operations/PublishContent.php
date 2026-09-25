<?php

namespace App\Operations;

use App\Contracts\Operation;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\Publication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PublishingService;
use DateTimeImmutable;

final class PublishContent implements Operation
{
    public function __construct(private readonly PublishingService $publishing) {}

    public function execute(User $actor, array $input): Publication
    {
        $content = $input['content_item'] instanceof ContentItem
            ? $input['content_item']
            : ContentItem::query()->findOrFail((int) $input['content_item_id']);

        $account = $input['social_account'] instanceof SocialAccount
            ? $input['social_account']
            : SocialAccount::query()->findOrFail((int) $input['social_account_id']);

        $assignment = $input['assignment'] ?? (
            isset($input['agent_assignment_id'])
                ? AgentAssignment::query()->findOrFail((int) $input['agent_assignment_id'])
                : null
        );

        $execution = $input['execution'] ?? (
            isset($input['agent_execution_id'])
                ? AgentExecution::query()->findOrFail((int) $input['agent_execution_id'])
                : null
        );

        $approval = $input['approval'] ?? (
            isset($input['approval_request_id'])
                ? ApprovalRequest::query()->findOrFail((int) $input['approval_request_id'])
                : null
        );

        $publication = $this->publishing->schedule(
            $actor,
            $content,
            $account,
            new DateTimeImmutable((string) $input['scheduled_at']),
            $approval,
            $assignment,
            $execution,
        );

        return $this->publishing->submit($actor, $publication, $assignment, $execution, $approval);
    }
}
