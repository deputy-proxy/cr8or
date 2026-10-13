<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\AgentAssignment;
use App\Models\AgentExecution;
use App\Models\ApprovalRequest;
use App\Models\ContentItem;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('publish-content')]
#[Description('Schedule publication-ready content through the governed CR8OR publishing boundary.')]
final class PublishContentTool extends GovernedCapabilityTool
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

    public function handle(Request $r, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($r, 'publication.publish', function () use ($r, $registry) {
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
            $as = isset($v['agent_assignment_id'])
                ? AgentAssignment::query()->findOrFail($v['agent_assignment_id'])
                : null;
            /** @var AgentExecution|null $ex */
            $ex = isset($v['agent_execution_id'])
                ? AgentExecution::query()->findOrFail($v['agent_execution_id'])
                : null;
            /** @var ApprovalRequest|null $ap */
            $ap = isset($v['approval_request_id'])
                ? ApprovalRequest::query()->findOrFail($v['approval_request_id'])
                : null;

            $input = [
                'content_item' => $c,
                'social_account' => $sa,
                'enterprise' => $c->enterprise,
                'target_context' => ['enterprise_id' => (int) $c->enterprise_id, 'content_item_id' => (int) $c->id, 'social_account_id' => (int) $sa->id],
                'assignment' => $as,
                'execution' => $ex,
                'approval' => $ap,
                ...$v,
            ];
            if (isset($v['agent_assignment_id'], $v['agent_execution_id'])) {
                $input['expert_slug'] = 'marketing';
            }
            $pub = $this->executeCapability($registry, $u, $input);

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

    /** @param array<string, mixed> $input
     * @return array{0: string, 1: mixed}|null
     */
    protected function humanAbility(User $actor, array $input): ?array
    {
        $content = $input['content_item'] ?? null;

        return $content instanceof ContentItem ? ['update', $content] : null;
    }
}