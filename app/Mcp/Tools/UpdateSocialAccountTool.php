<?php

namespace App\Mcp\Tools;

use App\Capabilities\CapabilityRegistry;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update-social-account')]
#[Description('Update non-credential metadata for an authorized social destination.')]
final class UpdateSocialAccountTool extends GovernedCapabilityTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'social_account_id' => $schema->integer()->min(1)->required(),
            'name' => $schema->string()->min(1)->max(255),
            'channel_id' => $schema->integer()->min(1),
            'agent_assignment_id' => $schema->integer()->min(1),
            'agent_execution_id' => $schema->integer()->min(1),
            'approval_request_id' => $schema->integer()->min(1),
        ];
    }

    public function handle(Request $request, CapabilityRegistry $registry): Response|ResponseFactory
    {
        return $this->executeWithErrors($request, 'marketing.social-account.update', function () use ($request, $registry) {
            $validated = $request->validate([
                'social_account_id' => ['required', 'integer', 'min:1', 'exists:social_accounts,id'],
                'name' => ['sometimes', 'string', 'min:1', 'max:255'],
                'channel_id' => ['sometimes', 'integer', 'min:1', 'exists:channels,id'],
                'agent_assignment_id' => ['nullable', 'integer', 'min:1', 'exists:agent_assignments,id'],
                'agent_execution_id' => ['nullable', 'integer', 'min:1', 'exists:agent_executions,id'],
                'approval_request_id' => ['nullable', 'integer', 'min:1', 'exists:approval_requests,id'],
            ]);

            $actor = $request->user();
            if (! $actor instanceof User) {
                throw new AuthenticationException;
            }

            $account = SocialAccount::query()->with('enterprise')->findOrFail((int) $validated['social_account_id']);
            $input = [
                'social_account' => $account,
                'enterprise' => $account->enterprise,
                'target_context' => ['enterprise_id' => (int) $account->enterprise_id, 'social_account_id' => (int) $account->id],
                ...$validated,
            ];
            if (isset($validated['agent_assignment_id'], $validated['agent_execution_id'])) {
                $input['expert_slug'] = 'marketing';
            }

            $updated = $this->executeCapability($registry, $actor, $input);

            return Response::structured([
                'success' => true,
                'result' => [
                    'id' => $updated->id,
                    'enterprise_id' => $updated->enterprise_id,
                    'channel_id' => $updated->channel_id,
                    'provider' => $updated->provider,
                    'name' => $updated->name,
                    'external_id' => $updated->external_id,
                    'status' => $updated->status,
                ],
            ]);
        });
    }

    /** @param array<string, mixed> $input
     * @return array{0: string, 1: mixed}|null
     */
    protected function humanAbility(User $actor, array $input): ?array
    {
        $account = $input['social_account'] ?? null;

        return $account instanceof SocialAccount ? ['update', $account] : null;
    }
}
