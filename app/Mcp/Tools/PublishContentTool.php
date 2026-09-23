<?php

namespace App\Mcp\Tools;

use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\McpCapabilityAuthorizer;
use App\Services\PublishingService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('publish-content')]
#[Description('Schedule publication-ready content through the governed CR8OR publishing boundary.')]
class PublishContentTool extends AuthorizedTool
{
    public function schema(JsonSchema $s): array
    {
        return [
            'content_item_id' => $s->integer()->min(1)->required(),
            'social_account_id' => $s->integer()->min(1)->required(),
            'scheduled_at' => $s->string()->required(),
            'agent_assignment_id' => $s->integer()->min(1),
            'agent_execution_id' => $s->integer()->min(1),
            'approval_request_id' => $s->integer()->min(1),
        ];
    }

    public function handle(Request $r, McpCapabilityAuthorizer $a, PublishingService $p): Response|ResponseFactory
    {
        return $this->executeWithErrors($r, 'mcp.publication.publish', function () use ($r, $a, $p) {
            $v = $r->validate([
                'content_item_id' => ['required', 'integer', 'min:1', 'exists:content_items,id'],
                'social_account_id' => ['required', 'integer', 'min:1', 'exists:social_accounts,id'],
                'scheduled_at' => ['required', 'date'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $u = $r->user();

            if (! $u instanceof User) {
                throw new AuthenticationException;
            }

            /** @var ContentItem $c */
            $c = ContentItem::query()->findOrFail($v['content_item_id']);
            /** @var SocialAccount $sa */
            $sa = SocialAccount::query()->findOrFail($v['social_account_id']);

            /** @var AgentAssignment|null $as */
            $as = null;
            if (isset($v['agent_assignment_id'])) {
                /** @var AgentAssignment $as */
                $as = AgentAssignment::query()->findOrFail($v['agent_assignment_id']);
            }

            /** @var AgentExecution|null $ex */
            $ex = null;
            if (isset($v['agent_execution_id'])) {
                /** @var AgentExecution $ex */
                $ex = AgentExecution::query()->findOrFail($v['agent_execution_id']);
            }

            /** @var ApprovalRequest|null $ap */
            $ap = null;
            if (isset($v['approval_request_id'])) {
                /** @var ApprovalRequest $ap */
                $ap = ApprovalRequest::query()->findOrFail($v['approval_request_id']);
            }

            $a->authorizeMutation(
                $u,
                'publication.publish',
                $c->enterprise,
                $as?->id,
                $ex?->id,
                $ap?->id,
                ['content_item_id' => $c->id],
                ['update', $c],
            );

            $pub = $p->schedule(
                $u,
                $c,
                $sa,
                new \DateTimeImmutable($v['scheduled_at']),
                $ap,
                $as,
                $ex,
            );
            $pub = $p->submit($u, $pub, $as, $ex, $ap);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $pub->id,
                    'status' => $pub->status,
                    'external_id' => $pub->external_id,
                ],
            ]);
        });
    }
}
